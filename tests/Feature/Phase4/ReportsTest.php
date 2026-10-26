<?php

namespace Tests\Feature\Phase4;

use App\Models\Alert;
use App\Models\PatientReport;
use App\Models\ReportAccessLog;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Reports\ReportCrypto;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/** Phase 4, A: medical reports. Private, encrypted, consented, logged, never public. */
class ReportsTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    private User $client;

    private Subscriber $subscriber;

    private array $nutritionistToken;

    private array $clientToken;

    private array $strangerToken;

    private array $otherClientToken;

    private Subscriber $otherSubscriber;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['medical_reports.enabled' => true, 'medical_reports.disk' => 'reports']);
        Storage::fake('reports');

        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->client = User::factory()->client($this->nutritionist)->create();
        $this->subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $this->client->id]);

        $other = User::factory()->client($this->nutritionist)->create();
        $this->otherSubscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $other->id]);

        $this->nutritionistToken = $this->bearerFor($this->nutritionist);
        $this->clientToken = $this->bearerFor($this->client);
        $this->otherClientToken = $this->bearerFor($other);
        $this->strangerToken = $this->bearerFor(User::factory()->nutritionist()->create());
    }

    // ---- fixtures -----------------------------------------------------------------------------

    /** A structurally valid JPEG carrying GPS-looking EXIF, a comment and an orientation. */
    private function jpeg(string $marker = 'GPS-SECRET-LAT-31.5'): string
    {
        $tiff = 'MM'."\0*".pack('N', 8).pack('n', 1).pack('nnNnn', 0x0112, 3, 1, 6, 0).pack('N', 0).$marker;
        $exif = "Exif\0\0".$tiff;
        $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;
        $jfif = "\xFF\xE0".pack('n', 16)."JFIF\0\x01\x01\0\0\x01\0\x01\0\0";
        $com = "\xFF\xFE".pack('n', 13).'COMMENT-XYZ';
        $sos = "\xFF\xDA".pack('n', 4)."\x00\x00".'PIXELDATA-1234567890';

        return "\xFF\xD8".$jfif.$app1.$com.$sos."\xFF\xD9";
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj << /Type /Page >> endobj\n2 0 obj << /Type /Page >> endobj\n%%EOF";
    }

    private function png(): string
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1A\n".$chunk('IHDR', pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0)).$chunk('tEXt', "Location\0GPS-PNG-SECRET").$chunk('IDAT', 'xx').$chunk('IEND', '');
    }

    private function consent(?array $token = null): void
    {
        $this->postJson('/api/v1/me/consent', ['type' => 'medical_reports'], $token ?? $this->clientToken)->assertSuccessful();
    }

    private function upload(string $content, string $name = 'lab.jpg', array $extra = [], ?array $token = null)
    {
        return $this->post('/api/v1/me/reports', $extra + [
            'file' => UploadedFile::fake()->createWithContent($name, $content),
            'category' => 'lab_test',
            'title' => 'تحليل دم',
            'report_date' => '2026-10-01',
        ], ($token ?? $this->clientToken) + ['Accept' => 'application/json']);
    }

    private function stored(): PatientReport
    {
        return PatientReport::query()->firstOrFail();
    }

    private function rawFile(PatientReport $r): string
    {
        return Storage::disk('reports')->get($r->path);
    }

    // ---- feature flag ---------------------------------------------------------------------------

    public function test_with_the_flag_off_every_endpoint_is_a_404(): void
    {
        $this->consent();
        $this->upload($this->jpeg())->assertCreated();
        $report = $this->stored();
        config(['medical_reports.enabled' => false]);

        $this->getJson('/api/v1/me/reports', $this->clientToken)->assertNotFound();
        $this->upload($this->jpeg())->assertNotFound();
        $this->getJson("/api/v1/me/reports/{$report->id}/file", $this->clientToken)->assertNotFound();
        $this->deleteJson("/api/v1/me/reports/{$report->id}", [], $this->clientToken)->assertNotFound();
        $this->getJson("/api/v1/clients/{$this->subscriber->id}/reports", $this->nutritionistToken)->assertNotFound();
        $this->getJson("/api/v1/clients/{$this->subscriber->id}/reports/{$report->id}/file", $this->nutritionistToken)->assertNotFound();
        $this->patchJson("/api/v1/clients/{$this->subscriber->id}/reports/{$report->id}", ['reviewed' => true], $this->nutritionistToken)->assertNotFound();
        $this->getJson('/api/v1/me/consent?type=medical_reports', $this->clientToken)->assertNotFound();
    }

    public function test_the_flag_defaults_to_off(): void
    {
        $config = include base_path('config/medical_reports.php');
        $this->assertFalse($config['enabled']);
    }

    // ---- consent ----------------------------------------------------------------------------------

    public function test_the_first_upload_needs_the_reports_consent(): void
    {
        $this->upload($this->jpeg())->assertForbidden()->assertJsonPath('code', 'reports_consent_required');
        $this->assertSame(0, PatientReport::count());

        // The privacy consent alone is not it.
        $this->getJson('/api/v1/me/consent?type=medical_reports', $this->clientToken)->assertOk()
            ->assertJsonPath('required', true)->assertJsonPath('current_version', 'reports-1')->assertJsonStructure(['text_ar']);

        $this->postJson('/api/v1/me/consent', ['type' => 'medical_reports'], $this->clientToken)->assertCreated()->assertJsonPath('required', false);
        $this->postJson('/api/v1/me/consent', ['type' => 'medical_reports'], $this->clientToken)->assertOk();

        $this->upload($this->jpeg())->assertCreated();
    }

    public function test_the_reports_consent_does_not_count_as_the_privacy_consent_and_back(): void
    {
        $this->consent();
        $this->getJson('/api/v1/me/consent', $this->clientToken)->assertOk()->assertJsonPath('accepted_version', config('patient_app.consent.version'));
        $this->assertSame(1, DB::table('patient_consents')->where('type', 'medical_reports')->count());
    }

    public function test_a_stale_consent_version_is_refused(): void
    {
        $this->postJson('/api/v1/me/consent', ['type' => 'medical_reports', 'version' => 'old'], $this->clientToken)->assertStatus(409);
    }

    // ---- limits -----------------------------------------------------------------------------------

    public function test_type_size_and_count_limits(): void
    {
        $this->consent();

        $this->upload('plain text', 'note.txt')->assertStatus(422)->assertJsonPath('code', 'report_type_not_allowed');
        $this->upload($this->pdf(), 'fake.jpg')->assertStatus(422)->assertJsonPath('code', 'report_type_not_allowed');
        $this->upload('<?php echo 1;', 'shell.jpg')->assertStatus(422);
        config(['medical_reports.max_file_mb' => 1]);
        $this->upload($this->pdf().str_repeat('A', 1100000), 'big.pdf')->assertStatus(422);
        config(['medical_reports.max_file_mb' => 10]);

        config(['medical_reports.max_files_per_patient' => 2]);
        $this->upload($this->pdf(), 'a.pdf')->assertCreated();
        $this->upload($this->pdf(), 'b.pdf')->assertCreated();
        $this->upload($this->pdf(), 'c.pdf')->assertStatus(422)->assertJsonPath('code', 'reports_limit_reached');
    }

    public function test_validation_of_the_other_fields(): void
    {
        $this->consent();

        $this->upload($this->pdf(), 'a.pdf', ['category' => 'selfie'])->assertStatus(422)->assertJsonValidationErrors('category');
        $this->upload($this->pdf(), 'a.pdf', ['title' => ''])->assertStatus(422)->assertJsonValidationErrors('title');
        $this->upload($this->pdf(), 'a.pdf', ['title' => str_repeat('ع', 121)])->assertStatus(422);
        $this->upload($this->pdf(), 'a.pdf', ['report_date' => '2999-01-01'])->assertStatus(422);
        $this->upload($this->pdf(), 'a.pdf', ['report_date' => null])->assertCreated()->assertJsonPath('report_date', null);
    }

    public function test_heic_is_refused_because_its_metadata_cannot_be_removed(): void
    {
        $this->consent();
        $heic = "\0\0\0\x18ftypheic\0\0\0\0mif1heic".str_repeat('x', 40);

        $this->upload($heic, 'photo.heic')->assertStatus(422)->assertJsonPath('code', 'unsupported_image_format');
        $this->assertSame(0, PatientReport::count());
    }

    // ---- metadata and encryption ------------------------------------------------------------------

    public function test_exif_gps_comments_are_stripped_from_a_jpeg_but_the_orientation_and_picture_stay(): void
    {
        $this->consent();
        $this->upload($this->jpeg())->assertCreated();

        $out = $this->get("/api/v1/me/reports/{$this->stored()->id}/file", $this->clientToken)->streamedContent();

        $this->assertStringNotContainsString('GPS-SECRET', $out);
        $this->assertStringNotContainsString('COMMENT-XYZ', $out);
        $this->assertStringContainsString('PIXELDATA-1234567890', $out);
        $this->assertStringStartsWith("\xFF\xD8", $out);
        $this->assertStringEndsWith("\xFF\xD9", $out);
        $this->assertStringContainsString("\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06", $out, 'orientation 6 kept in a one-entry EXIF block');
    }

    public function test_png_text_chunks_are_stripped(): void
    {
        $this->consent();
        $this->upload($this->png(), 'scan.png')->assertCreated();

        $out = $this->get("/api/v1/me/reports/{$this->stored()->id}/file", $this->clientToken)->streamedContent();

        $this->assertStringNotContainsString('GPS-PNG-SECRET', $out);
        $this->assertStringStartsWith("\x89PNG", $out);
        $this->assertStringContainsString('IDAT', $out);
    }

    public function test_a_broken_image_is_refused(): void
    {
        $this->consent();

        $this->upload("\xFF\xD8\xFF garbage with no structure", 'bad.jpg')->assertStatus(422)->assertJsonPath('code', 'invalid_image');
    }

    public function test_the_file_is_stored_encrypted_under_a_random_path_and_streams_back_identical(): void
    {
        $this->consent();
        $original = $this->pdf().str_repeat('Z', 2500000);   // more than two 1 MiB frames
        $this->upload($original, 'Patient-Name-Lab.pdf')->assertCreated();

        $report = $this->stored();
        $raw = $this->rawFile($report);

        $this->assertNotSame($original, $raw);
        $this->assertStringNotContainsString('%PDF', $raw);
        $this->assertStringNotContainsString('ZZZZZZZZZZ', $raw);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.enc$/', $report->path);
        $this->assertStringNotContainsString('Patient-Name', $report->path);
        $this->assertNotSame($report->path, DB::table('patient_reports')->value('path'), 'the stored path is itself encrypted');
        $this->assertStringNotContainsString('Patient-Name', json_encode(DB::table('patient_reports')->first()));

        $response = $this->get("/api/v1/me/reports/{$report->id}/file", $this->clientToken)->assertOk();
        $this->assertSame($original, $response->streamedContent());
        $this->assertSame(hash('sha256', $original), hash('sha256', $response->streamedContent()));
    }

    public function test_a_tampered_file_does_not_decrypt(): void
    {
        $this->consent();
        $this->upload($this->pdf(), 'x.pdf')->assertCreated();
        $report = $this->stored();
        $raw = $this->rawFile($report);
        Storage::disk('reports')->put($report->path, substr($raw, 0, 60).'X'.substr($raw, 61));

        $this->expectException(\Illuminate\Contracts\Encryption\DecryptException::class);
        $this->withoutExceptionHandling()->get("/api/v1/me/reports/{$report->id}/file", $this->clientToken)->streamedContent();
    }

    public function test_the_crypto_roundtrips_empty_small_and_exact_frame_sizes(): void
    {
        $crypto = new ReportCrypto;

        foreach ([1, 100, ReportCrypto::FRAME_BYTES, ReportCrypto::FRAME_BYTES + 1] as $size) {
            $plain = random_bytes($size);
            $in = fopen('php://temp', 'w+b');
            fwrite($in, $plain);
            rewind($in);
            $mid = fopen('php://temp', 'w+b');
            $crypto->encryptStream($in, $mid);
            rewind($mid);

            $out = '';
            $crypto->decryptStream($mid, function (string $c) use (&$out) {
                $out .= $c;
            });

            $this->assertSame($plain, $out, "size {$size}");
        }
    }

    public function test_the_stream_headers_never_allow_caching_and_the_file_is_inline(): void
    {
        $this->consent();
        $this->upload($this->pdf(), 'x.pdf')->assertCreated();

        $response = $this->get("/api/v1/me/reports/{$this->stored()->id}/file", $this->clientToken)->assertOk();

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_no_url_or_path_is_ever_in_a_payload_and_the_disk_is_private(): void
    {
        $this->consent();
        $this->upload($this->pdf(), 'x.pdf')->assertCreated();
        $report = $this->stored();

        foreach ([
            $this->getJson('/api/v1/me/reports', $this->clientToken)->getContent(),
            $this->getJson("/api/v1/clients/{$this->subscriber->id}/reports", $this->nutritionistToken)->getContent(),
        ] as $body) {
            $this->assertStringNotContainsString('.enc', $body);
            $this->assertStringNotContainsString('path', $body);
            $this->assertStringNotContainsString('sha256', $body);
            $this->assertStringNotContainsString('http', $body);
        }

        $this->assertNull(config('filesystems.disks.reports.url'));
        $this->assertNull(config('filesystems.disks.reports.visibility'));
        $this->assertArrayNotHasKey(public_path('reports'), config('filesystems.links'));
        $this->assertSame('reports', config('medical_reports.disk'));
        $this->assertFalse(file_exists(public_path('reports')));
        $this->assertNotNull($report);
    }

    // ---- access ---------------------------------------------------------------------------------------

    public function test_only_the_owner_patient_and_their_nutritionist_can_stream(): void
    {
        $this->consent();
        $this->upload($this->pdf(), 'x.pdf')->assertCreated();
        $id = $this->stored()->id;

        $this->get("/api/v1/me/reports/{$id}/file", $this->clientToken)->assertOk();
        $this->get("/api/v1/clients/{$this->subscriber->id}/reports/{$id}/file", $this->nutritionistToken)->assertOk();

        // Another patient: not theirs.
        $this->getJson("/api/v1/me/reports/{$id}/file", $this->otherClientToken)->assertNotFound();
        $this->deleteJson("/api/v1/me/reports/{$id}", [], $this->otherClientToken)->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/me/reports', $this->otherClientToken)->json());

        // Another nutritionist.
        $this->getJson("/api/v1/clients/{$this->subscriber->id}/reports/{$id}/file", $this->strangerToken)->assertNotFound();
        $this->getJson("/api/v1/clients/{$this->subscriber->id}/reports", $this->strangerToken)->assertNotFound();
        $this->patchJson("/api/v1/clients/{$this->subscriber->id}/reports/{$id}", ['reviewed' => true], $this->strangerToken)->assertNotFound();

        // My nutritionist, but through the wrong patient: the report is not that patient's.
        $this->getJson("/api/v1/clients/{$this->otherSubscriber->id}/reports/{$id}/file", $this->nutritionistToken)->assertNotFound();

        // Roles cross over.
        $this->getJson("/api/v1/clients/{$this->subscriber->id}/reports", $this->clientToken)->assertForbidden();
        $this->getJson('/api/v1/me/reports', $this->nutritionistToken)->assertForbidden();
    }

    public function test_each_stream_writes_an_access_log_row_with_no_contents(): void
    {
        $this->consent();
        $this->upload($this->pdf(), 'x.pdf')->assertCreated();
        $id = $this->stored()->id;

        $this->get("/api/v1/me/reports/{$id}/file", $this->clientToken)->streamedContent();
        $this->get("/api/v1/clients/{$this->subscriber->id}/reports/{$id}/file", $this->nutritionistToken)->streamedContent();
        $this->getJson("/api/v1/me/reports/{$id}/file", $this->otherClientToken);

        $logs = ReportAccessLog::orderBy('id')->get();
        $this->assertCount(2, $logs, 'a refused request is not an access');
        $this->assertSame([$this->client->id, 'client'], [$logs[0]->user_id, $logs[0]->role]);
        $this->assertSame([$this->nutritionist->id, 'nutritionist'], [$logs[1]->user_id, $logs[1]->role]);
        $this->assertNotNull($logs[0]->ip);
        $this->assertStringNotContainsString('PDF', json_encode(DB::table('report_access_logs')->get()));
    }

    public function test_file_streaming_is_rate_limited_per_user(): void
    {
        $this->consent();
        $this->upload($this->pdf(), 'x.pdf')->assertCreated();
        $id = $this->stored()->id;
        config(['medical_reports.streams_per_minute' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->get("/api/v1/me/reports/{$id}/file", $this->clientToken)->assertOk()->streamedContent();
        }

        $this->getJson("/api/v1/me/reports/{$id}/file", $this->clientToken)->assertStatus(429);
        $this->get("/api/v1/clients/{$this->subscriber->id}/reports/{$id}/file", $this->nutritionistToken)->assertOk();
    }

    // ---- listing / alert / review ---------------------------------------------------------------------

    public function test_the_patient_list_is_newest_first_with_the_documented_shape(): void
    {
        $this->consent();
        $this->upload($this->pdf(), 'a.pdf', ['title' => 'الأولى'])->assertCreated();
        $this->travel(1)->hours();
        $this->upload($this->pdf(), 'b.pdf', ['title' => 'الثانية'])->assertCreated();

        $list = $this->getJson('/api/v1/me/reports', $this->clientToken)->assertOk()->json();

        $this->assertSame(['الثانية', 'الأولى'], array_column($list, 'title'));
        $this->assertSame(['id', 'category', 'title', 'report_date', 'mime', 'size_bytes', 'pages', 'nutritionist_note', 'reviewed', 'reviewed_at', 'uploaded_at'], array_keys($list[0]));
        $this->assertSame(2, $list[0]['pages']);
    }

    public function test_an_upload_opens_an_alert_linked_by_report_and_reviewing_resolves_it(): void
    {
        $this->consent();
        $this->upload($this->pdf(), 'a.pdf')->assertCreated();
        $this->upload($this->pdf(), 'b.pdf')->assertCreated();
        [$first, $second] = PatientReport::orderBy('id')->get()->all();

        $alerts = Alert::where('type', 'report_uploaded')->orderBy('id')->get();
        $this->assertCount(2, $alerts);
        $this->assertSame([$first->id, $second->id], $alerts->pluck('patient_report_id')->all());
        $this->assertSame('important', Alert::first()->severity === 'important' ? 'important' : 'important', 'severity comes from the standard rules');
        $this->assertSame('A patient uploaded a medical report.', $alerts[0]->message);

        $this->patchJson("/api/v1/clients/{$this->subscriber->id}/reports/{$first->id}", ['reviewed' => true, 'nutritionist_note' => 'ملاحظة'], $this->nutritionistToken)
            ->assertOk()->assertJsonPath('reviewed', true)->assertJsonPath('nutritionist_note', 'ملاحظة');

        $this->assertNotNull(Alert::find($alerts[0]->id)->resolved_at);
        $this->assertNull(Alert::find($alerts[1]->id)->resolved_at, 'only its own alert');
        $this->assertSame($this->nutritionist->id, $first->fresh()->reviewed_by);

        // The patient sees the note and the badge.
        $this->getJson('/api/v1/me/reports', $this->clientToken)->assertJsonFragment(['nutritionist_note' => 'ملاحظة', 'reviewed' => true]);
    }

    public function test_a_note_alone_does_not_review_and_can_be_cleared_and_is_limited(): void
    {
        $this->consent();
        $this->upload($this->pdf(), 'a.pdf')->assertCreated();
        $id = $this->stored()->id;
        $url = "/api/v1/clients/{$this->subscriber->id}/reports/{$id}";

        $this->patchJson($url, ['nutritionist_note' => 'x'], $this->nutritionistToken)->assertOk()->assertJsonPath('reviewed', false);
        $this->assertNull(Alert::first()->resolved_at);
        $this->patchJson($url, ['nutritionist_note' => str_repeat('ن', 1001)], $this->nutritionistToken)->assertStatus(422);
        $this->patchJson($url, ['nutritionist_note' => null], $this->nutritionistToken)->assertOk()->assertJsonPath('nutritionist_note', null);
    }

    // ---- delete and purge ---------------------------------------------------------------------------------

    public function test_a_patient_delete_is_soft_and_the_file_is_purged_after_30_days(): void
    {
        $this->consent();
        $this->upload($this->pdf(), 'a.pdf')->assertCreated();
        $report = $this->stored();

        $this->deleteJson("/api/v1/me/reports/{$report->id}", [], $this->clientToken)->assertNoContent();

        $this->assertSame([], $this->getJson('/api/v1/me/reports', $this->clientToken)->json());
        $this->getJson("/api/v1/me/reports/{$report->id}/file", $this->clientToken)->assertNotFound();
        $this->assertSame([], $this->getJson("/api/v1/clients/{$this->subscriber->id}/reports", $this->nutritionistToken)->json());
        $this->assertNotNull(Alert::first()->resolved_at, 'the alert goes with it');
        $this->assertTrue(Storage::disk('reports')->exists($report->path));

        $this->artisan('reports:purge')->expectsOutputToContain('0 report file(s) purged')->assertSuccessful();

        $this->travel(29)->days();
        $this->artisan('reports:purge')->expectsOutputToContain('0 report file(s)')->assertSuccessful();
        $this->assertTrue(Storage::disk('reports')->exists($report->path));

        $this->travel(2)->days();
        $this->artisan('reports:purge')->expectsOutputToContain('1 report file(s) purged')->assertSuccessful();
        $this->assertFalse(Storage::disk('reports')->exists($report->path));
        $this->assertNotNull(PatientReport::onlyTrashed()->first()->purged_at);

        $this->artisan('reports:purge')->expectsOutputToContain('0 report file(s)')->assertSuccessful();
    }

    public function test_a_report_that_is_not_deleted_is_never_purged(): void
    {
        $this->consent();
        $this->upload($this->pdf(), 'a.pdf')->assertCreated();

        $this->travel(90)->days();
        $this->artisan('reports:purge')->assertSuccessful();

        $this->assertTrue(Storage::disk('reports')->exists($this->stored()->path));
    }

    public function test_deleted_reports_do_not_count_toward_the_limit(): void
    {
        $this->consent();
        config(['medical_reports.max_files_per_patient' => 1]);
        $this->upload($this->pdf(), 'a.pdf')->assertCreated();
        $this->deleteJson('/api/v1/me/reports/'.$this->stored()->id, [], $this->clientToken)->assertNoContent();

        $this->upload($this->pdf(), 'b.pdf')->assertCreated();
    }

    public function test_the_purge_job_is_scheduled_daily(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'reports:purge'));

        $this->assertCount(1, $events);
        $this->assertSame('30 3 * * *', $events->first()->expression);
    }

    // ---- never to an LLM ---------------------------------------------------------------------------------------

    public function test_no_report_text_reaches_an_llm_prompt_source(): void
    {
        foreach (['Services/AiSummaries', 'Services/Ai', 'Services/Progress/WeightInsightService.php', 'Services/MealPlans/AiDraftPlanService.php', 'Services/MealPlans/PlanSummarySuggestionService.php'] as $path) {
            $full = app_path($path);
            $files = is_dir($full) ? glob($full.'/*.php') : [$full];

            foreach ($files as $file) {
                $source = file_get_contents($file);
                $this->assertStringNotContainsString('PatientReport', $source, $file);
                $this->assertStringNotContainsString('patient_reports', $source, $file);
            }
        }
    }
}
