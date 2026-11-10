<?php

namespace Tests\Feature\Alerts;

use App\Models\Alert;
use App\Models\PatientQuickRequest;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/** POST /alerts/read-all, and AlertResource's quick_request_id. */
class AlertReadAllTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function alert(Subscriber $subscriber, array $attributes = []): Alert
    {
        return Alert::create(['subscriber_id' => $subscriber->id, 'type' => Alert::TYPE_NO_LOG, 'message' => 'x'] + $attributes);
    }

    private function roster(User $nutritionist): Subscriber
    {
        return Subscriber::factory()->active()->create(['nutritionist_id' => $nutritionist->id]);
    }

    public function test_it_marks_the_callers_unread_alerts_read_and_returns_the_count(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $a = $this->roster($nutritionist);
        $b = $this->roster($nutritionist);
        $unread = [$this->alert($a), $this->alert($a, ['type' => Alert::TYPE_MILESTONE]), $this->alert($b)];
        $alreadyRead = $this->alert($b, ['is_read' => true]);
        $readStamp = $alreadyRead->fresh()->updated_at;
        // Issued before the clock moves (JWT validation uses the real clock).
        $headers = $this->bearerFor($nutritionist);

        $this->travel(5)->minutes();

        $this->postJson('/api/v1/alerts/read-all', [], $headers)
            ->assertOk()
            ->assertExactJson(['updated' => 3]);

        foreach ($unread as $alert) {
            $this->assertTrue($alert->fresh()->is_read);
        }
        // Untouched, not rewritten: its updated_at did not move.
        $this->assertTrue($alreadyRead->fresh()->updated_at->equalTo($readStamp));
    }

    public function test_it_runs_one_update_query(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = $this->roster($nutritionist);
        foreach (range(1, 5) as $_) {
            $this->alert($subscriber);
        }
        $headers = $this->bearerFor($nutritionist);

        DB::enableQueryLog();
        $this->postJson('/api/v1/alerts/read-all', [], $headers)->assertExactJson(['updated' => 5]);
        $updates = collect(DB::getQueryLog())->filter(fn ($q) => str_starts_with(strtolower(ltrim($q['query'])), 'update') && str_contains($q['query'], 'alerts'));
        DB::disableQueryLog();

        $this->assertCount(1, $updates);
    }

    public function test_another_nutritionists_alerts_are_never_touched(): void
    {
        $mine = User::factory()->nutritionist()->create();
        $theirs = User::factory()->nutritionist()->create();
        $myAlert = $this->alert($this->roster($mine));
        $theirSubscriber = $this->roster($theirs);
        $theirAlert = $this->alert($theirSubscriber);

        $this->postJson('/api/v1/alerts/read-all', [], $this->bearerFor($mine))->assertExactJson(['updated' => 1]);
        // Even when their patient is named explicitly.
        $this->postJson('/api/v1/alerts/read-all', ['subscriber_id' => $theirSubscriber->id], $this->bearerFor($mine))->assertExactJson(['updated' => 0]);

        $this->assertTrue($myAlert->fresh()->is_read);
        $this->assertFalse($theirAlert->fresh()->is_read);
    }

    public function test_the_severity_filter_is_respected(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = $this->roster($nutritionist);
        $important = $this->alert($subscriber, ['severity' => Alert::SEVERITY_IMPORTANT]);
        $normal = $this->alert($subscriber, ['severity' => Alert::SEVERITY_NORMAL]);

        $this->postJson('/api/v1/alerts/read-all', ['severity' => 'important'], $this->bearerFor($nutritionist))
            ->assertExactJson(['updated' => 1]);

        $this->assertTrue($important->fresh()->is_read);
        $this->assertFalse($normal->fresh()->is_read);
    }

    public function test_the_subscriber_filter_is_respected(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $a = $this->roster($nutritionist);
        $b = $this->roster($nutritionist);
        $forA = $this->alert($a);
        $forB = $this->alert($b);

        $this->postJson('/api/v1/alerts/read-all', ['subscriber_id' => $a->id], $this->bearerFor($nutritionist))
            ->assertExactJson(['updated' => 1]);

        $this->assertTrue($forA->fresh()->is_read);
        $this->assertFalse($forB->fresh()->is_read);
    }

    public function test_both_filters_together(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $a = $this->roster($nutritionist);
        $b = $this->roster($nutritionist);
        $target = $this->alert($a, ['severity' => Alert::SEVERITY_IMPORTANT]);
        $others = [$this->alert($a), $this->alert($b, ['severity' => Alert::SEVERITY_IMPORTANT])];

        $this->postJson('/api/v1/alerts/read-all', ['subscriber_id' => $a->id, 'severity' => 'important'], $this->bearerFor($nutritionist))
            ->assertExactJson(['updated' => 1]);

        $this->assertTrue($target->fresh()->is_read);
        foreach ($others as $alert) {
            $this->assertFalse($alert->fresh()->is_read);
        }
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();

        $this->postJson('/api/v1/alerts/read-all', ['severity' => 'urgent'], $this->bearerFor($nutritionist))
            ->assertUnprocessable()->assertJsonValidationErrors('severity');
        $this->postJson('/api/v1/alerts/read-all', ['subscriber_id' => 999999], $this->bearerFor($nutritionist))
            ->assertUnprocessable()->assertJsonValidationErrors('subscriber_id');
    }

    public function test_it_requires_a_token(): void
    {
        $this->postJson('/api/v1/alerts/read-all')->assertUnauthorized();
    }

    public function test_a_role_without_alerts_view_is_forbidden(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = $this->roster($nutritionist);
        $alert = $this->alert($subscriber);
        $client = User::factory()->create(['nutritionist_id' => $nutritionist->id]);
        $client->assignRole('client');
        $this->assertFalse($client->can('alerts.view'));

        $this->postJson('/api/v1/alerts/read-all', [], $this->bearerFor($client))->assertForbidden();
        $this->assertFalse($alert->fresh()->is_read);
    }

    public function test_the_resource_exposes_quick_request_id_set_and_null(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = $this->roster($nutritionist);
        $request = PatientQuickRequest::create(['subscriber_id' => $subscriber->id, 'reason' => 'craving', 'status' => PatientQuickRequest::STATUS_OPEN]);
        $withRequest = $this->alert($subscriber, ['type' => Alert::TYPE_QUICK_REQUEST, 'quick_request_id' => $request->id]);
        $without = $this->alert($subscriber);

        $rows = collect($this->getJson('/api/v1/alerts', $this->bearerFor($nutritionist))->assertOk()->json('data'))->keyBy('id');

        $this->assertSame($request->id, $rows[$withRequest->id]['quick_request_id']);
        $this->assertArrayHasKey('quick_request_id', $rows[$without->id]);
        $this->assertNull($rows[$without->id]['quick_request_id']);

        // The single-alert response carries it too.
        $this->patchJson("/api/v1/alerts/{$withRequest->id}/read", [], $this->bearerFor($nutritionist))
            ->assertOk()->assertJsonPath('quick_request_id', $request->id);
    }
}
