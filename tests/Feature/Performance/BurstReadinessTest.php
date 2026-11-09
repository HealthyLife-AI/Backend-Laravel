<?php

namespace Tests\Feature\Performance;

use App\Exceptions\Ai\AiGenerationException;
use App\Jobs\SendPushNotifications;
use App\Models\Meal;
use App\Models\MealPlan;
use App\Models\NotificationPreference;
use App\Models\NutritionistPost;
use App\Models\PatientNotification;
use App\Models\Subscriber;
use App\Models\SubscriberCheckinSetting;
use App\Models\SubscriberMeasurementSchedule;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\MealPlans\AiDraftPlanService;
use App\Services\Notifications\FcmPushService;
use App\Services\Notifications\NotificationService;
use App\Services\Posts\PostService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Concerns\AuthenticatesForApi;
use Tests\Feature\Notifications\FcmPushServiceTest;
use Tests\TestCase;

/**
 * Bursts: a post or the morning push to many patients writes the in-app rows at once and queues the
 * FCM sends in chunks; FCM has short timeouts; one AI draft is capped in total; the cron works the queue.
 */
class BurstReadinessTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    private string $fixturePath;

    private int $devices = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->nutritionist = User::factory()->nutritionist()->create();

        $this->fixturePath = storage_path('framework/testing/fake-firebase-credentials-burst.json');
        file_put_contents($this->fixturePath, json_encode([
            'project_id' => 'test-project',
            'client_email' => 'test@test-project.iam.gserviceaccount.com',
            'private_key' => FcmPushServiceTest::FIXTURE_PRIVATE_KEY,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));
        config(['firebase.credentials_path' => $this->fixturePath, 'push.chunk_size' => 2]);

        // Midday in the clinic: outside everyone's default quiet hours (22:00-07:00).
        $this->travelTo(CarbonImmutable::parse('today 12:00', config('scheduling.timezone')));
    }

    protected function tearDown(): void
    {
        @unlink($this->fixturePath);
        parent::tearDown();
    }

    private function fcmFake(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'x']),
        ]);
    }

    private function fcmSends(): int
    {
        return Http::recorded(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com'))->count();
    }

    private function notifications(): NotificationService
    {
        return app(NotificationService::class);
    }

    /** @return list<Subscriber> */
    private function patients(int $count, bool $withToken = true): array
    {
        $made = [];

        for ($i = 0; $i < $count; $i++) {
            $user = User::factory()->client($this->nutritionist)->create(['fcm_token' => $withToken ? 'device-'.(++$this->devices) : null]);
            $made[] = Subscriber::factory()->active()->withPlanInForce()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $user->id]);
        }

        return $made;
    }

    private function newPost(): NutritionistPost
    {
        return NutritionistPost::create(['author_id' => $this->nutritionist->id, 'title' => 'نصيحة', 'body' => 'نص', 'audience' => 'all', 'status' => 'draft']);
    }

    private function publishPost(): NutritionistPost
    {
        return app(PostService::class)->publish($this->newPost());
    }

    // ---- posts: rows now, pushes queued -----------------------------------------------------------------------------------------

    public function test_publishing_a_post_writes_every_row_in_the_request_and_queues_the_sends_in_chunks(): void
    {
        Bus::fake([SendPushNotifications::class]);
        $this->fcmFake();
        $this->patients(5);

        $this->publishPost();

        $this->assertSame(5, PatientNotification::where('type', 'education_post')->count(), 'every in-app row exists when the request ends');
        $this->assertSame(0, PatientNotification::whereNotNull('pushed_at')->count(), 'nothing was sent inside the request');
        $this->assertSame(0, $this->fcmSends());

        Bus::assertDispatchedTimes(SendPushNotifications::class, 3);
        Bus::assertDispatched(SendPushNotifications::class, fn ($job) => count($job->notificationIds) === 2);
        Bus::assertDispatched(SendPushNotifications::class, fn ($job) => count($job->notificationIds) === 1);
    }

    public function test_the_default_chunk_is_100_notifications(): void
    {
        $config = require base_path('config/push.php');
        $this->assertSame(100, $config['chunk_size']);
        $this->assertSame(40, $config['job_budget_seconds']);
    }

    public function test_the_queued_jobs_then_push_each_patient_once_even_if_run_twice(): void
    {
        Queue::fake();
        $this->fcmFake();
        $this->patients(2);

        $this->publishPost();

        Queue::assertPushed(SendPushNotifications::class, 1);
        $this->assertSame(0, $this->fcmSends());

        // The cron's worker runs the job; a retry of the same job sends nothing twice.
        $job = Queue::pushed(SendPushNotifications::class)->first();
        $job->handle($this->notifications());
        $job->handle($this->notifications());

        $this->assertSame(2, $this->fcmSends());
        $this->assertSame(2, PatientNotification::whereNotNull('pushed_at')->count());
    }

    public function test_quiet_hours_preferences_and_missing_tokens_are_decided_before_queuing_exactly_as_before(): void
    {
        Bus::fake([SendPushNotifications::class]);
        $this->fcmFake();
        [$quiet, $off, $noToken, $normal] = $this->patients(4);
        $noToken->user->forceFill(['fcm_token' => null])->save();
        NotificationPreference::for($off->user)->forceFill(['education' => false])->save();
        NotificationPreference::for($quiet->user)->forceFill(['quiet_hours_enabled' => true, 'quiet_start' => '00:00', 'quiet_end' => '23:59'])->save();

        $this->publishPost();

        $reason = fn (Subscriber $s) => PatientNotification::where('user_id', $s->user_id)->value('push_skipped');
        $this->assertSame('quiet_hours', $reason($quiet));
        $this->assertSame('category_off', $reason($off));
        $this->assertSame('no_token', $reason($noToken));
        $this->assertNull($reason($normal));

        $row = PatientNotification::where('user_id', $normal->user_id)->first();
        Bus::assertDispatchedTimes(SendPushNotifications::class, 1);
        Bus::assertDispatched(SendPushNotifications::class, fn ($job) => $job->notificationIds === [$row->id]);
    }

    public function test_publishing_again_does_not_queue_or_duplicate_anything(): void
    {
        Bus::fake([SendPushNotifications::class]);
        $this->fcmFake();
        $this->patients(2);
        $posts = app(PostService::class);
        $post = $this->newPost();

        $posts->publish($post);
        $posts->unpublish($post);
        $posts->publish($post);

        Bus::assertDispatchedTimes(SendPushNotifications::class, 1);
        $this->assertSame(2, PatientNotification::where('type', 'education_post')->count());
    }

    public function test_a_chunk_that_runs_out_of_time_queues_the_rest_as_a_new_job_after_making_progress(): void
    {
        $this->fcmFake();
        $this->patients(2);
        config(['push.chunk_size' => 10]);
        Queue::fake();
        $this->publishPost();
        $job = Queue::pushed(SendPushNotifications::class)->first();
        $this->assertCount(2, $job->notificationIds);
        Queue::fake();   // forget it; only what the job itself re-queues is looked at

        config(['push.job_budget_seconds' => 0]);
        $job->handle($this->notifications());

        $this->assertSame(1, $this->fcmSends(), 'at least one send per run, so a run always makes progress');
        Queue::assertPushed(SendPushNotifications::class, fn ($again) => count($again->notificationIds) === 1);
    }

    public function test_a_queue_that_is_down_never_fails_the_publish_and_the_rows_stay(): void
    {
        Exceptions::fake();
        $this->fcmFake();
        $this->patients(2);
        $this->mock(Dispatcher::class, fn ($mock) => $mock->shouldReceive('dispatch')->andThrow(new \RuntimeException('queue down')));

        $this->publishPost();

        $this->assertSame(2, PatientNotification::where('type', 'education_post')->count());
        Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'queue down');
    }

    // ---- the scheduled bulk commands ----------------------------------------------------------------------------------------------

    public function test_the_morning_push_creates_the_rows_then_queues_the_sends(): void
    {
        Bus::fake([SendPushNotifications::class]);
        $this->fcmFake();
        foreach ($this->patients(5) as $s) {
            Meal::create(['meal_plan_id' => MealPlan::where('subscriber_id', $s->id)->value('id'), 'name' => 'breakfast', 'day_index' => null, 'sort_order' => 0]);
        }

        $this->artisan('morning:send')->assertSuccessful();

        $this->assertSame(5, PatientNotification::where('type', 'morning_message')->count());
        $this->assertSame(0, $this->fcmSends());
        Bus::assertDispatchedTimes(SendPushNotifications::class, 3);

        // The dedupe key still stops a second run from writing or queueing again.
        $this->artisan('morning:send')->assertSuccessful();
        $this->assertSame(5, PatientNotification::where('type', 'morning_message')->count());
        Bus::assertDispatchedTimes(SendPushNotifications::class, 3);
    }

    public function test_the_log_reminder_queues_its_pushes(): void
    {
        Bus::fake([SendPushNotifications::class]);
        $this->fcmFake();
        $this->travelTo(CarbonImmutable::parse('today 20:00', config('scheduling.timezone')));
        $this->patients(3);

        $this->artisan('notifications:send-log-reminders')->expectsOutputToContain('Queued 3 reminder push(es)')->assertSuccessful();

        $this->assertSame(0, $this->fcmSends());
        Bus::assertDispatchedTimes(SendPushNotifications::class, 2);
    }

    public function test_measurement_reminders_and_check_ins_queue_their_pushes(): void
    {
        Bus::fake([SendPushNotifications::class]);
        $this->fcmFake();
        [$a, $b] = $this->patients(2);
        SubscriberMeasurementSchedule::create(['subscriber_id' => $a->id, 'interval_days' => 14, 'next_due_on' => now()->toDateString()]);
        SubscriberCheckinSetting::create(['subscriber_id' => $b->id, 'interval_days' => 7, 'next_due_on' => now()->toDateString()]);

        $this->artisan('measurements:remind')->assertSuccessful();
        $this->artisan('checkins:open')->assertSuccessful();

        $this->assertSame(0, $this->fcmSends());
        Bus::assertDispatchedTimes(SendPushNotifications::class, 2);
        $this->assertSame(1, PatientNotification::where('type', 'measurement_due')->count());
        $this->assertSame(1, PatientNotification::where('type', 'checkin_due')->count());
    }

    public function test_a_single_notification_outside_a_fan_out_still_goes_straight_out(): void
    {
        Bus::fake([SendPushNotifications::class]);
        $this->fcmFake();
        [$s] = $this->patients(1);

        $this->notifications()->notify($s->user, 'system', 'follow_up_resumed');

        Bus::assertNothingDispatched();
        $this->assertSame(1, $this->fcmSends());
    }

    // ---- FCM timeouts --------------------------------------------------------------------------------------------------------------

    public function test_fcm_requests_have_a_3_second_connect_and_5_second_total_timeout(): void
    {
        $options = [];
        Http::fake(function ($request, $requestOptions) use (&$options) {
            $options[$request->url()] = [$requestOptions['connect_timeout'] ?? null, $requestOptions['timeout'] ?? null];

            return str_contains($request->url(), 'oauth2') ? Http::response(['access_token' => 't', 'expires_in' => 3600]) : Http::response(['name' => 'x']);
        });

        app(FcmPushService::class)->send('device', 'title', 'body');

        $this->assertSame(3, FcmPushService::CONNECT_TIMEOUT_SECONDS);
        $this->assertSame(5, FcmPushService::TIMEOUT_SECONDS);
        $this->assertCount(2, $options, 'the token exchange and the send');
        foreach ($options as $url => [$connect, $total]) {
            $this->assertEquals(3, $connect, "connect timeout of {$url}");
            $this->assertEquals(5, $total, "total timeout of {$url}");
        }
    }

    // ---- the AI draft worst case -----------------------------------------------------------------------------------------------------

    private function aiSetUp(int $configuredTimeout = 12): void
    {
        Sleep::fake(syncWithCarbon: true);
        config(['ai.base_url' => 'https://fake-llm.test', 'ai.api_key' => 'k', 'ai.model' => 'openai/gpt-oss-120b', 'ai.timeout' => $configuredTimeout]);
    }

    private function clock(): float
    {
        return (float) now()->format('U.u');
    }

    public function test_one_request_never_gets_more_time_than_the_draft_budget(): void
    {
        $this->aiSetUp(configuredTimeout: 60);
        $timeouts = [];
        Http::fake(function ($request, $options) use (&$timeouts) {
            $timeouts[] = $options['timeout'];

            return Http::response(['choices' => [['message' => ['content' => '{"a":1}']]]]);
        });

        app(OpenAiCompatibleClient::class)->withTotalBudget(AiDraftPlanService::TOTAL_BUDGET_SECONDS)->chatJson([['role' => 'user', 'content' => 'x']]);

        $this->assertCount(1, $timeouts);
        $this->assertLessThanOrEqual(AiDraftPlanService::TOTAL_BUDGET_SECONDS, $timeouts[0]);
        $this->assertGreaterThan(AiDraftPlanService::TOTAL_BUDGET_SECONDS - 1, $timeouts[0]);
    }

    public function test_without_a_budget_the_configured_timeout_applies_as_before(): void
    {
        $this->aiSetUp(configuredTimeout: 12);
        $timeouts = [];
        Http::fake(function ($request, $options) use (&$timeouts) {
            $timeouts[] = $options['timeout'];

            return Http::response(['choices' => [['message' => ['content' => '{"a":1}']]]]);
        });

        app(OpenAiCompatibleClient::class)->chatJson([['role' => 'user', 'content' => 'x']]);

        $this->assertEquals(12, $timeouts[0]);
    }

    public function test_no_request_is_started_when_the_budget_is_gone(): void
    {
        $this->aiSetUp();
        Http::fake();
        $client = app(OpenAiCompatibleClient::class)->withTotalBudget(OpenAiCompatibleClient::MIN_REQUEST_SECONDS - 1);

        try {
            $client->chatJson([['role' => 'user', 'content' => 'x']]);
            $this->fail('expected the budget to refuse the request');
        } catch (AiGenerationException $e) {
            $this->assertStringContainsString('used up', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    // ---- the scheduler -----------------------------------------------------------------------------------------------------------------

    /** @return \Illuminate\Support\Collection<int, Event> */
    private function events(string $needle)
    {
        Artisan::call('list');   // boots the console kernel, which registers the schedule

        return collect(app(Schedule::class)->events())->filter(fn (Event $e) => str_contains($e->command, $needle));
    }

    public function test_the_queue_is_worked_every_minute_from_the_cron_in_the_foreground_and_last(): void
    {
        Artisan::call('list');   // boots the console kernel, which registers the schedule (once: a second call registers it again)
        $all = array_values(app(Schedule::class)->events());
        $matching = array_values(array_filter($all, fn (Event $e) => str_contains($e->command, 'queue:work')));

        $this->assertCount(1, $matching);
        $event = $matching[0];
        $this->assertStringContainsString('queue:work database --stop-when-empty --max-time=50 --tries=3', $event->command);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt, 'a killed run heals its lock in 10 minutes, not 24 hours');
        $this->assertFalse($event->runInBackground, 'Dokku removes the cron container when schedule:run exits: a background worker would be killed with it');

        // Last, so the pushes queued by the commands that run before it in the same minute are sent in that run.
        $this->assertSame($event, end($all));
    }

    public function test_every_bulk_command_is_scheduled_without_overlap_and_with_a_lock_that_expires(): void
    {
        foreach (['alerts:evaluate', 'morning:send', 'measurements:remind', 'checkins:open', 'notifications:send-log-reminders', 'db:backup'] as $command) {
            $event = $this->events($command)->first();

            $this->assertNotNull($event, "{$command} is scheduled");
            $this->assertTrue($event->withoutOverlapping, "{$command} does not overlap itself");
            $this->assertLessThanOrEqual(60, $event->expiresAt, "{$command}: the lock expires in minutes, not the 24 h default");
        }
    }

    public function test_the_cron_entry_in_app_json_is_the_scheduler_every_minute(): void
    {
        $cron = json_decode((string) file_get_contents(base_path('app.json')), true)['cron'];

        $this->assertSame([['command' => 'php artisan schedule:run', 'schedule' => '* * * * *']], $cron);
    }

    public function test_the_procfile_serves_through_the_start_script_and_still_migrates_on_release(): void
    {
        $procfile = (string) file_get_contents(base_path('Procfile'));

        $this->assertStringContainsString('web: sh bin/web.sh', $procfile);
        $this->assertStringContainsString('release: php artisan migrate --force', $procfile);
        $this->assertStringNotContainsString('optimize', explode('release:', $procfile)[1], 'optimize runs where the files are served from, not in the throwaway release container');

        $script = (string) file_get_contents(base_path('bin/web.sh'));
        $this->assertStringContainsString('php artisan optimize', $script);
        $this->assertStringContainsString('PHP_INI_SCAN_DIR', $script);
        $this->assertStringContainsString('--no-reload', $script);
        $this->assertStringContainsString('exec php artisan serve', $script);
        $this->assertStringNotContainsString("\r", $script, 'LF line endings: a CR would break the script on Linux');
        $this->assertLessThan(strpos($script, 'export PHP_INI_SCAN_DIR'), strpos($script, 'php artisan optimize'), 'optimize before anything is exported');
    }

    // ---- bin/web.sh, run for real against a stand-in for the nixpkgs `php` wrapper ------------------------------------------------

    private const WRAPPER_DEFAULT = '/nix/store/abc-php-env/lib';

    /**
     * Runs bin/web.sh with a fake `php` first on the PATH. The fake behaves like the nixpkgs wrapper: it
     * sets PHP_INI_SCAN_DIR to its own directory only when the variable is unset (--set-default), reports
     * the extensions it is told are missing, and records every call and the scan directory `artisan serve` gets.
     *
     * @return array{output: string, log: list<string>}
     */
    private function runWebScript(string $missingExtensions = '', int $optimizeExit = 0): array
    {
        if (trim((string) @shell_exec('sh -c "echo ok" 2>&1')) !== 'ok') {
            $this->markTestSkipped('No POSIX sh available to run bin/web.sh.');
        }

        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'webscript-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $log = $dir.DIRECTORY_SEPARATOR.'calls.log';

        file_put_contents($dir.DIRECTORY_SEPARATOR.'php', <<<'SH'
        #!/bin/sh
        # Stand-in for `php` on Nixpacks: the wrapper's --set-default PHP_INI_SCAN_DIR.
        : "${PHP_INI_SCAN_DIR=$FAKE_WRAPPER_DEFAULT}"
        echo "call: $*" >> "$FAKE_LOG"
        case "$1" in
          artisan)
            case "$2" in
              optimize) echo "fake optimize"; exit "${FAKE_OPTIMIZE_EXIT:-0}" ;;
              serve) echo "serve scan_dir=$PHP_INI_SCAN_DIR" >> "$FAKE_LOG"; exit 0 ;;
            esac ;;
          -r)
            case "$2" in
              *getenv*) printf '%s' "$PHP_INI_SCAN_DIR" ;;
              *extension_loaded*) echo "extension check scan_dir=$PHP_INI_SCAN_DIR" >> "$FAKE_LOG"; printf '%s' "$FAKE_MISSING" ;;
              *opcache.enable_cli*) printf '1' ;;
            esac ;;
        esac
        SH);
        chmod($dir.DIRECTORY_SEPARATOR.'php', 0755);

        $process = proc_open(
            ['sh', base_path('bin/web.sh')],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            ['PATH' => $dir.PATH_SEPARATOR.getenv('PATH'), 'FAKE_LOG' => $log, 'FAKE_WRAPPER_DEFAULT' => self::WRAPPER_DEFAULT, 'FAKE_MISSING' => $missingExtensions, 'FAKE_OPTIMIZE_EXIT' => (string) $optimizeExit, 'PHP_CLI_SERVER_WORKERS' => '8', 'SystemRoot' => (string) getenv('SystemRoot')],
        );
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        proc_close($process);

        $calls = is_file($log) ? array_values(array_filter(explode("\n", (string) file_get_contents($log)))) : [];
        @unlink($log);
        @unlink($dir.DIRECTORY_SEPARATOR.'php');
        @rmdir($dir);

        return ['output' => $output, 'log' => $calls];
    }

    public function test_the_web_script_adds_our_directory_after_the_one_php_already_uses(): void
    {
        $run = $this->runWebScript();

        $served = collect($run['log'])->first(fn ($line) => str_starts_with($line, 'serve scan_dir='));
        $this->assertNotNull($served, 'artisan serve was started');
        $this->assertStringStartsWith('serve scan_dir='.self::WRAPPER_DEFAULT.':', $served, "PHP's own directory stays first, so its extensions still load");
        $this->assertStringEndsWith('/.php', $served, 'our OPcache directory comes after it');
        $this->assertStringNotContainsString('scan_dir=:', $served, 'never an empty first entry: nixpkgs PHP has no compiled-in directory to fill it');

        $this->assertStringContainsString("PHP_INI_SCAN_DIR as PHP has it: '".self::WRAPPER_DEFAULT."'", $run['output']);
        $this->assertStringContainsString('Extension check with that scan directory: all required extensions load.', $run['output']);
        $this->assertStringContainsString('OPcache is on for the web process.', $run['output']);
        $this->assertStringContainsString('PHP_CLI_SERVER_WORKERS=8', $run['output']);
    }

    public function test_the_web_script_checks_the_extensions_with_the_new_scan_directory_before_using_it(): void
    {
        $run = $this->runWebScript();

        $checked = collect($run['log'])->first(fn ($line) => str_starts_with($line, 'extension check scan_dir='));
        $this->assertStringStartsWith('extension check scan_dir='.self::WRAPPER_DEFAULT.':', (string) $checked);
        $this->assertStringEndsWith('/.php', (string) $checked);
    }

    public function test_the_web_script_starts_without_opcache_when_an_extension_would_go_missing(): void
    {
        $run = $this->runWebScript(missingExtensions: 'pdo_mysql mbstring');

        $served = collect($run['log'])->first(fn ($line) => str_starts_with($line, 'serve scan_dir='));
        $this->assertNotNull($served, 'the server still starts');
        $this->assertSame('serve scan_dir='.self::WRAPPER_DEFAULT, $served, 'exactly the environment it has today: no .php directory');
        $this->assertStringContainsString('WARNING: with that scan directory these PHP extensions would not load: pdo_mysql mbstring', $run['output']);
        $this->assertStringContainsString('Starting WITHOUT OPcache', $run['output']);
        $this->assertStringNotContainsString('OPcache is on for the web process.', $run['output']);
    }

    public function test_the_web_script_optimizes_first_and_still_starts_if_that_fails(): void
    {
        $run = $this->runWebScript(optimizeExit: 1);

        $this->assertSame('call: artisan optimize', $run['log'][0], 'optimize is the first thing it does');
        $this->assertStringContainsString('WARNING: php artisan optimize failed', $run['output']);
        $this->assertNotNull(collect($run['log'])->first(fn ($line) => str_starts_with($line, 'serve scan_dir=')), 'the app starts anyway');
    }

    public function test_the_opcache_ini_turns_it_on_for_the_cli_server(): void
    {
        $ini = parse_ini_file(base_path('.php/opcache.ini'));

        $this->assertSame('1', (string) $ini['opcache.enable_cli']);
        $this->assertSame('128', (string) $ini['opcache.memory_consumption']);
        $this->assertSame('16', (string) $ini['opcache.interned_strings_buffer']);
        $this->assertSame('20000', (string) $ini['opcache.max_accelerated_files']);
        $this->assertSame('0', (string) $ini['opcache.validate_timestamps']);
    }

    public function test_nothing_outside_config_reads_env(): void
    {
        $offenders = [];

        foreach (['app', 'routes', 'bootstrap'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir))) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $calls = collect(token_get_all((string) file_get_contents($file->getPathname())))
                    ->filter(fn ($t) => is_array($t) && $t[0] === T_STRING && $t[1] === 'env')->count();

                if ($calls > 0) {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $offenders, 'env() returns null once the config is cached: read config() instead');
    }

    public function test_only_demo_accounts_can_get_another_api_limit_for_a_load_test(): void
    {
        $this->travelBack();   // the token check reads the real clock
        config(['load_test.demo_api_requests_per_minute' => 3]);
        $demo = User::factory()->nutritionist()->create();
        $demo->forceFill(['is_demo' => true])->save();
        $demoToken = $this->bearerFor($demo);
        $realToken = $this->bearerFor($this->nutritionist);

        foreach (range(1, 3) as $ignored) {
            $this->getJson('/api/v1/auth/me', $demoToken)->assertOk();
        }
        $this->getJson('/api/v1/auth/me', $demoToken)->assertStatus(429);

        foreach (range(1, 6) as $ignored) {
            $this->getJson('/api/v1/auth/me', $realToken)->assertOk();   // the real account keeps the 120 default
        }

        $config = require base_path('config/load_test.php');
        $this->assertSame(120, $config['demo_api_requests_per_minute'], 'unset: the same limit as everyone');
    }

    public function test_the_admin_status_reports_the_queue_and_the_runtime(): void
    {
        $this->travelBack();   // the token check reads the real clock
        $token = $this->bearerFor(User::factory()->admin()->create());
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time() - 90]);
        config(['queue.default' => 'database']);

        $response = $this->getJson('/api/v1/system/scheduler-status', $token)->assertOk();

        $this->assertSame(1, $response->json('queue.pending'));
        $this->assertGreaterThanOrEqual(85, $response->json('queue.oldest_pending_seconds'));
        $this->assertSame(0, $response->json('queue.failed'));
        $this->assertSame(PHP_VERSION, $response->json('runtime.php'));
        $this->assertIsBool($response->json('runtime.config_cached'));
        $this->assertIsBool($response->json('runtime.opcache.enabled'));
        $this->assertGreaterThanOrEqual(1, $response->json('runtime.server_workers'));
    }
}
