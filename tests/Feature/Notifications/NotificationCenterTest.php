<?php

namespace Tests\Feature\Notifications;

use App\Models\Food;
use App\Models\NotificationPreference;
use App\Models\PatientNotification;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\Concerns\FakesPush;
use Tests\TestCase;

/**
 * The patient notification center: plan / follow-up events, the 5-minute
 * debounce, quiet hours in the patient's timezone, category switches,
 * locale, and the inbox endpoints.
 */
class NotificationCenterTest extends TestCase
{
    use AuthenticatesForApi, FakesPush, RefreshDatabase;

    private User $nutritionist;

    private Subscriber $patient;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->fakePush();

        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->patient = Subscriber::factory()->active()->withPlanInForce()->forNutritionist($this->nutritionist)->create();
        $this->user = $this->patient->user;
        $this->user->forceFill(['fcm_token' => 'device-1'])->save();
        $this->prefs(['quiet_hours_enabled' => false]);
    }

    private function prefs(array $values): NotificationPreference
    {
        $prefs = NotificationPreference::for($this->user);
        $prefs->forceFill($values)->save();

        return $prefs;
    }

    private function nurse(): array
    {
        return $this->bearerFor($this->nutritionist);
    }

    private function draftPlan(): int
    {
        $food = Food::factory()->create();

        return $this->postJson("/api/v1/clients/{$this->patient->id}/meal-plans", ['meals' => [
            ['name' => 'lunch', 'items' => [['food_id' => $food->id, 'quantity_grams' => 200]]],
        ]], $this->nurse())->assertCreated()->json('id');
    }

    private function editPlan(int $planId, int $grams): void
    {
        $food = Food::query()->first();
        $this->putJson("/api/v1/clients/{$this->patient->id}/meal-plans/{$planId}", ['meals' => [
            ['name' => 'lunch', 'items' => [['food_id' => $food->id, 'quantity_grams' => $grams]]],
        ]], $this->nurse())->assertOk();
    }

    // ---- events + debounce ---------------------------------------------------

    public function test_launch_day_activate_then_edit_two_minutes_later_is_one_notification(): void
    {
        $planId = $this->draftPlan();
        $this->editPlan($planId, 220); // a draft: tells the patient nothing
        $this->assertSame(0, PatientNotification::count());

        $this->travelTo(now()->subMinutes(2));
        $this->postJson("/api/v1/clients/{$this->patient->id}/meal-plans/{$planId}/activate", [], $this->nurse())->assertOk();
        $this->travelBack();
        $this->editPlan($planId, 250);

        $this->assertSame(1, PatientNotification::count());
        $row = PatientNotification::first();
        $this->assertSame(['plan', 'plan_activated', "plan:{$planId}"], [$row->category, $row->type, $row->dedupe_key]);
        $this->assertNull($row->read_at);
        $this->assertCount(1, $this->pushes);
    }

    public function test_an_edit_after_the_debounce_window_is_a_new_notification(): void
    {
        $planId = $this->draftPlan();
        $this->travelTo(now()->subMinutes(6));
        $this->postJson("/api/v1/clients/{$this->patient->id}/meal-plans/{$planId}/activate", [], $this->nurse())->assertOk();
        $this->travelBack();

        $this->editPlan($planId, 250);

        $this->assertSame(['plan_activated', 'plan_updated'], PatientNotification::orderBy('id')->pluck('type')->all());
        $this->assertCount(2, $this->pushes);
    }

    public function test_resuming_follow_up_notifies_the_patient(): void
    {
        $this->postJson("/api/v1/clients/{$this->patient->id}/archive", [], $this->nurse())->assertOk();
        $this->postJson("/api/v1/clients/{$this->patient->id}/resume", [], $this->nurse())->assertOk();
        $this->postJson("/api/v1/clients/{$this->patient->id}/resume", [], $this->nurse())->assertOk(); // already resumed: nothing

        $this->assertSame(['system:follow_up_resumed'], PatientNotification::get()->map(fn ($n) => "{$n->category}:{$n->type}")->all());
    }

    // ---- quiet hours, switches, locale -----------------------------------------

    public function test_quiet_hours_spanning_midnight_in_the_patients_timezone_drop_every_push_but_keep_the_row(): void
    {
        $this->prefs(['quiet_hours_enabled' => true, 'quiet_start' => '22:00', 'quiet_end' => '07:00', 'timezone' => 'Asia/Gaza']);
        $service = app(NotificationService::class);

        // 23:30 and 06:30 in Gaza are inside; 07:00 and 21:59 are not.
        foreach (['23:30' => true, '06:30' => true, '07:00' => false, '21:59' => false] as $time => $quiet) {
            $at = CarbonImmutable::parse("2026-10-05 {$time}", 'Asia/Gaza');
            $this->assertSame($quiet, $service->inQuietHours(NotificationPreference::for($this->user), $at), $time);
        }

        $this->travelTo(CarbonImmutable::parse('today 23:30', 'Asia/Gaza')->isFuture()
            ? CarbonImmutable::parse('yesterday 23:30', 'Asia/Gaza')
            : CarbonImmutable::parse('today 23:30', 'Asia/Gaza'));

        $row = $service->notify($this->user, 'nutritionist', 'review_new');
        $reminder = $service->notify($this->user, 'meals', 'log_reminder');
        $system = $service->notify($this->user, 'system', 'follow_up_resumed');

        foreach ([$row, $reminder, $system] as $n) {
            $this->assertSame('quiet_hours', $n->fresh()->push_skipped);
        }
        $this->assertSame([], $this->pushes);
        $this->assertSame(3, PatientNotification::count());
    }

    public function test_the_8pm_reminder_respects_quiet_hours(): void
    {
        $this->prefs(['quiet_hours_enabled' => true, 'quiet_start' => '19:00', 'quiet_end' => '21:00', 'timezone' => null]);
        $this->travelTo(CarbonImmutable::parse('today 20:00', config('scheduling.timezone'))->isFuture()
            ? CarbonImmutable::parse('yesterday 20:00', config('scheduling.timezone'))
            : CarbonImmutable::parse('today 20:00', config('scheduling.timezone')));

        $this->artisan('notifications:send-log-reminders')->assertSuccessful();

        $this->assertSame([], $this->pushes);
        $this->assertSame('quiet_hours', PatientNotification::sole()->push_skipped);
    }

    public function test_a_switched_off_category_keeps_the_row_without_a_push_and_system_cannot_be_switched_off(): void
    {
        $this->prefs(['plan' => false]);
        $service = app(NotificationService::class);

        $this->assertSame('category_off', $service->notify($this->user, 'plan', 'plan_activated')->fresh()->push_skipped);
        $this->assertNotNull($service->notify($this->user, 'system', 'follow_up_resumed')->fresh()->pushed_at);
        $this->assertCount(1, $this->pushes);
    }

    public function test_texts_follow_the_patients_locale(): void
    {
        $service = app(NotificationService::class);
        $this->assertSame('خطتك الغذائية جاهزة', $service->notify($this->user, 'plan', 'plan_activated')->title);

        $this->putJson('/api/v1/me/notification-preferences', ['locale' => 'en'], $this->bearerFor($this->user))->assertOk();
        $this->assertSame('Your meal plan is ready', $service->notify($this->user, 'plan', 'plan_activated')->title);
        $this->assertSame('Your meal plan is ready', $this->pushes[1]['title']);
    }

    // ---- endpoints ---------------------------------------------------------

    public function test_preferences_default_and_validation(): void
    {
        $me = $this->bearerFor($this->user);
        NotificationPreference::query()->delete();

        $this->getJson('/api/v1/me/notification-preferences', $me)->assertOk()
            ->assertJsonPath('locale', 'ar')
            ->assertJsonPath('categories.system', true)
            ->assertJsonPath('quiet_hours', ['enabled' => true, 'start' => '22:00', 'end' => '07:00'])
            ->assertJsonPath('timezone', null)
            ->assertJsonPath('effective_timezone', config('scheduling.timezone'));

        $this->putJson('/api/v1/me/notification-preferences', ['timezone' => 'Mars/Olympus'], $me)
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_timezone')->assertJsonValidationErrors('timezone');
        $this->putJson('/api/v1/me/notification-preferences', ['quiet_start' => '25:00', 'locale' => 'fr'], $me)
            ->assertUnprocessable()->assertJsonValidationErrors(['quiet_start', 'locale']);

        $this->putJson('/api/v1/me/notification-preferences', ['timezone' => 'Asia/Gaza', 'meals' => false, 'quiet_start' => '23:00'], $me)
            ->assertOk()->assertJsonPath('effective_timezone', 'Asia/Gaza')->assertJsonPath('categories.meals', false)->assertJsonPath('quiet_hours.start', '23:00');
    }

    public function test_inbox_filters_counts_and_reads_only_the_patients_own_rows(): void
    {
        $service = app(NotificationService::class);
        $a = $service->notify($this->user, 'plan', 'plan_activated');
        $service->notify($this->user, 'nutritionist', 'review_new');
        $other = Subscriber::factory()->active()->forNutritionist($this->nutritionist)->create();
        $theirs = $service->notify($other->user, 'plan', 'plan_activated');
        $me = $this->bearerFor($this->user);

        $this->getJson('/api/v1/me/notifications', $me)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/me/notifications?category=plan', $me)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id);
        $this->getJson('/api/v1/me/notifications/unread-count', $me)->assertOk()->assertJsonPath('unread', 2);

        $this->postJson("/api/v1/me/notifications/{$a->id}/read", [], $me)->assertOk()->assertJsonPath('read_at', fn ($v) => $v !== null);
        $this->getJson('/api/v1/me/notifications?unread=1', $me)->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/me/notifications/{$theirs->id}/read", [], $me)->assertNotFound();

        $this->postJson('/api/v1/me/notifications/read-all', [], $me)->assertOk()->assertJsonPath('marked', 1);
        $this->getJson('/api/v1/me/notifications/unread-count', $me)->assertJsonPath('unread', 0);
        $this->assertNull($theirs->fresh()->read_at);
    }
}
