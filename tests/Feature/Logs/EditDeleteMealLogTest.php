<?php

namespace Tests\Feature\Logs;

use App\Models\Food;
use App\Models\MealItem;
use App\Models\MealLog;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Adherence\AdherenceService;
use App\Services\Alerts\AlertEvaluationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * BR-15 (edit/delete window) and BR-19 (backdating limit): a patient can
 * correct or remove their own meal log for 7 days after its `logged_at`,
 * and can't date a new one more than 7 days back.
 */
class EditDeleteMealLogTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $client;

    private Subscriber $subscriber;

    private MealItem $planItem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        [$this->client, $this->subscriber, $this->planItem] = $this->makePatient();
    }

    /** @return array{0: User, 1: Subscriber, 2: MealItem} */
    private function makePatient(): array
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $client = User::factory()->create(['nutritionist_id' => $nutritionist->id]);
        $client->assignRole('client');
        $subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $nutritionist->id, 'user_id' => $client->id]);

        $planId = DB::table('meal_plans')->insertGetId([
            'subscriber_id' => $subscriber->id, 'created_by' => $nutritionist->id, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $mealId = DB::table('meals')->insertGetId([
            'meal_plan_id' => $planId, 'name' => 'lunch', 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $item = MealItem::create(['meal_id' => $mealId, 'food_id' => Food::factory()->create()->id, 'quantity_grams' => 100, 'sort_order' => 0]);

        return [$client, $subscriber, $item];
    }

    private function auth(?User $as = null): array
    {
        return $this->bearerFor($as ?? $this->client);
    }

    private function offPlanLog(array $overrides = [], ?Subscriber $for = null): MealLog
    {
        return MealLog::create($overrides + [
            'subscriber_id' => ($for ?? $this->subscriber)->id,
            'food_id' => Food::factory()->create()->id,
            'quantity_grams' => 100,
            'meal_type' => 'snack',
            'logged_at' => now()->subHour(),
        ]);
    }

    /** A log that reached the server $days days ago (the edit window counts from arrival). */
    private function arrivedDaysAgo(float $days, array $overrides = []): MealLog
    {
        $this->travelTo(now()->subMinutes((int) round($days * 1440)));
        $log = $this->offPlanLog($overrides);
        $this->travelBack();

        return $log;
    }

    private function onPlanLog(): MealLog
    {
        return MealLog::create([
            'subscriber_id' => $this->subscriber->id,
            'food_id' => $this->planItem->food_id,
            'meal_item_id' => $this->planItem->id,
            'meal_type' => 'lunch',
            'quantity_grams' => 100,
            'logged_at' => now()->subHour(),
        ]);
    }

    // ---- edit -------------------------------------------------------------

    public function test_a_patient_edits_the_quantity_and_the_response_recomputes_macros(): void
    {
        $log = $this->offPlanLog();
        $before = $this->getJson('/api/v1/me/meal-logs', $this->auth())->json('data.0.macros.calories');

        $response = $this->patchJson("/api/v1/me/meal-logs/{$log->id}", ['quantity_grams' => 250], $this->auth())->assertOk();

        $response->assertJsonPath('id', $log->id)->assertJsonPath('quantity_grams', 250);
        $this->assertEqualsWithDelta($before * 2.5, $response->json('macros.calories'), 0.6);
        $this->assertSame('250.0', $log->fresh()->quantity_grams);
        $this->assertNotNull($response->json('editable_until'));
    }

    public function test_the_time_and_the_meal_type_of_an_off_plan_log_can_be_edited(): void
    {
        $log = $this->offPlanLog();
        $newTime = now()->subHours(5)->startOfSecond();

        $this->patchJson("/api/v1/me/meal-logs/{$log->id}", ['logged_at' => $newTime->toIso8601String(), 'meal_type' => 'dinner'], $this->auth())
            ->assertOk()
            ->assertJsonPath('meal_type', 'dinner')
            ->assertJsonPath('logged_at', $newTime->toIso8601String());
    }

    public function test_an_on_plan_logs_meal_type_cannot_be_changed_but_its_quantity_can(): void
    {
        $log = $this->onPlanLog();

        $this->patchJson("/api/v1/me/meal-logs/{$log->id}", ['meal_type' => 'dinner'], $this->auth())
            ->assertUnprocessable()->assertJsonValidationErrors('meal_type');
        $this->assertSame('lunch', $log->fresh()->meal_type);

        $this->patchJson("/api/v1/me/meal-logs/{$log->id}", ['quantity_grams' => 180], $this->auth())
            ->assertOk()->assertJsonPath('meal_type', 'lunch')->assertJsonPath('is_on_plan', true);
    }

    public function test_the_food_the_plan_link_and_the_retry_key_cannot_be_changed(): void
    {
        $log = $this->offPlanLog();

        foreach ([
            ['food_id' => Food::factory()->create()->id],
            ['meal_item_id' => $this->planItem->id],
            ['idempotency_key' => (string) Str::uuid()],
        ] as $body) {
            $this->patchJson("/api/v1/me/meal-logs/{$log->id}", $body + ['quantity_grams' => 120], $this->auth())
                ->assertUnprocessable()->assertJsonValidationErrors(array_key_first($body));
        }
        $this->assertSame('100.0', $log->fresh()->quantity_grams, 'a refused edit changes nothing');
    }

    public function test_an_edit_needs_at_least_one_field_and_valid_values(): void
    {
        $log = $this->offPlanLog();

        $this->patchJson("/api/v1/me/meal-logs/{$log->id}", [], $this->auth())->assertUnprocessable();
        $this->patchJson("/api/v1/me/meal-logs/{$log->id}", ['quantity_grams' => 0], $this->auth())->assertUnprocessable()->assertJsonValidationErrors('quantity_grams');
        $this->patchJson("/api/v1/me/meal-logs/{$log->id}", ['meal_type' => 'brunch'], $this->auth())->assertUnprocessable()->assertJsonValidationErrors('meal_type');
    }

    public function test_the_time_moves_at_most_seven_days_and_never_into_the_future(): void
    {
        $log = $this->offPlanLog();

        $this->patchJson("/api/v1/me/meal-logs/{$log->id}", ['logged_at' => now()->addHour()->toIso8601String()], $this->auth())
            ->assertUnprocessable()->assertJsonValidationErrors('logged_at');

        // More than 7 days from where it is now: refused, so a fresh log can't
        // be walked weeks back.
        $this->patchJson("/api/v1/me/meal-logs/{$log->id}", ['logged_at' => now()->subDays(7)->subHours(2)->toIso8601String()], $this->auth())
            ->assertUnprocessable()->assertJsonPath('code', 'logged_at_move_too_far')->assertJsonPath('max_move_days', 7)
            ->assertJsonValidationErrors('logged_at');
        $this->patchJson("/api/v1/me/meal-logs/{$log->id}", ['logged_at' => now()->subDays(7)->toIso8601String()], $this->auth())
            ->assertOk();

        // Another move of up to 7 days from its NEW place is allowed — but never
        // past the rejection limit.
        config(['patient_app.reject_after_days' => 10]);
        $this->patchJson("/api/v1/me/meal-logs/{$log->id}", ['logged_at' => now()->subDays(12)->toIso8601String()], $this->auth())
            ->assertUnprocessable()->assertJsonPath('code', 'entry_too_old');
    }

    public function test_moving_logged_at_recomputes_is_late_against_arrival(): void
    {
        $log = $this->offPlanLog();
        $this->assertFalse($log->fresh()->is_late);

        $this->patchJson("/api/v1/me/meal-logs/{$log->id}", ['logged_at' => now()->subDays(7)->subHour()->toIso8601String()], $this->auth())
            ->assertOk()->assertJsonPath('is_late', true);
        $this->patchJson("/api/v1/me/meal-logs/{$log->id}", ['logged_at' => now()->subDays(2)->toIso8601String()], $this->auth())
            ->assertOk()->assertJsonPath('is_late', false);
    }

    public function test_an_old_meal_that_arrived_today_can_still_be_edited(): void
    {
        $log = $this->offPlanLog(['logged_at' => now()->subDays(20)]);

        $this->patchJson("/api/v1/me/meal-logs/{$log->id}", ['quantity_grams' => 150], $this->auth())->assertOk();
    }

    public function test_last_logged_at_follows_the_newest_remaining_log_after_an_edit(): void
    {
        $older = $this->offPlanLog(['logged_at' => now()->subHours(10)]);
        $newer = $this->offPlanLog(['logged_at' => now()->subHours(2)]);
        $this->subscriber->forceFill(['last_logged_at' => $newer->logged_at])->save();

        $moved = now()->subHours(20)->startOfSecond();
        $this->patchJson("/api/v1/me/meal-logs/{$newer->id}", ['logged_at' => $moved->toIso8601String()], $this->auth())->assertOk();

        $this->assertEquals($older->logged_at->timestamp, $this->subscriber->fresh()->last_logged_at->timestamp);
    }

    // ---- the edit window (from arrival) ---------------------------------------------------

    public function test_the_window_closes_seven_days_after_the_log_arrived(): void
    {
        $this->travelTo(now()->startOfSecond());
        $inside = $this->arrivedDaysAgo(7 - 1 / 1440);
        $outside = $this->arrivedDaysAgo(7 + 1 / 1440);

        $this->patchJson("/api/v1/me/meal-logs/{$inside->id}", ['quantity_grams' => 150], $this->auth())->assertOk();

        $this->patchJson("/api/v1/me/meal-logs/{$outside->id}", ['quantity_grams' => 150], $this->auth())
            ->assertForbidden()->assertJsonPath('code', 'log_locked');
        $this->deleteJson("/api/v1/me/meal-logs/{$outside->id}", [], $this->auth())
            ->assertForbidden()->assertJsonPath('code', 'log_locked');
        $this->assertNotNull($outside->fresh(), 'a locked log is not deleted');

        $this->deleteJson("/api/v1/me/meal-logs/{$inside->id}", [], $this->auth())->assertNoContent();
    }

    public function test_a_locked_log_answers_403_before_its_body_is_validated(): void
    {
        $locked = $this->arrivedDaysAgo(9);

        $this->patchJson("/api/v1/me/meal-logs/{$locked->id}", ['quantity_grams' => -5], $this->auth())
            ->assertForbidden()->assertJsonPath('code', 'log_locked');
    }

    public function test_the_window_length_comes_from_config(): void
    {
        config(['patient_app.edit_window_days' => 1]);
        $log = $this->arrivedDaysAgo(2);

        $this->patchJson("/api/v1/me/meal-logs/{$log->id}", ['quantity_grams' => 150], $this->auth())
            ->assertForbidden()->assertJsonPath('code', 'log_locked');

        config(['patient_app.edit_window_days' => 3]);
        $this->patchJson("/api/v1/me/meal-logs/{$log->id}", ['quantity_grams' => 150], $this->auth())->assertOk();
    }

    public function test_every_log_response_says_until_when_it_can_be_edited(): void
    {
        $log = $this->offPlanLog(['logged_at' => now()->subHours(3)->startOfSecond()]);

        $row = $this->getJson('/api/v1/me/meal-logs', $this->auth())->assertOk()->json('data.0');

        $this->assertSame($log->created_at->addDays(7)->toIso8601String(), $row['editable_until']);
    }

    // ---- delete -----------------------------------------------------------

    public function test_deleting_a_log_recomputes_last_logged_at_and_adherence(): void
    {
        $older = $this->offPlanLog(['logged_at' => now()->subHours(10)]);
        $newer = $this->offPlanLog(['logged_at' => now()->subHours(2)]);
        $this->subscriber->forceFill(['last_logged_at' => $newer->logged_at])->save();

        $this->deleteJson("/api/v1/me/meal-logs/{$newer->id}", [], $this->auth())->assertNoContent();
        $this->assertEquals($older->logged_at->timestamp, $this->subscriber->fresh()->last_logged_at->timestamp);

        $this->deleteJson("/api/v1/me/meal-logs/{$older->id}", [], $this->auth())->assertNoContent();
        $fresh = $this->subscriber->fresh();
        $this->assertNull($fresh->last_logged_at);
        $this->assertSame(AdherenceService::STATUS_STOPPED, $fresh->adherence_status);
        $this->assertDatabaseCount('meal_logs', 0);
    }

    public function test_deleting_twice_is_a_404_the_second_time(): void
    {
        $log = $this->offPlanLog();

        $this->deleteJson("/api/v1/me/meal-logs/{$log->id}", [], $this->auth())->assertNoContent();
        $this->deleteJson("/api/v1/me/meal-logs/{$log->id}", [], $this->auth())->assertNotFound();
    }

    public function test_deleting_a_log_re_evaluates_the_patients_alerts(): void
    {
        config(['scheduling.self_trigger' => true]);
        $log = $this->offPlanLog();
        $evaluated = [];
        $this->mock(AlertEvaluationService::class, function ($mock) use (&$evaluated) {
            $mock->shouldReceive('evaluate')->andReturnUsing(function (Subscriber $s) use (&$evaluated) {
                $evaluated[] = $s->id;
            });
        });

        $this->deleteJson("/api/v1/me/meal-logs/{$log->id}", [], $this->auth())->assertNoContent();

        $this->assertContains($this->subscriber->id, $evaluated);
    }

    // ---- isolation and roles ----------------------------------------------

    public function test_another_patients_log_is_a_404_for_edit_and_delete(): void
    {
        [$stranger, $strangerSubscriber] = $this->makePatient();
        $theirs = $this->offPlanLog([], $strangerSubscriber);

        $this->patchJson("/api/v1/me/meal-logs/{$theirs->id}", ['quantity_grams' => 999], $this->auth())->assertNotFound();
        $this->deleteJson("/api/v1/me/meal-logs/{$theirs->id}", [], $this->auth())->assertNotFound();

        $this->assertSame('100.0', $theirs->fresh()->quantity_grams);
        // The same request from the owner works, so the 404 was about ownership.
        $this->patchJson("/api/v1/me/meal-logs/{$theirs->id}", ['quantity_grams' => 120], $this->auth($stranger))->assertOk();
    }

    public function test_a_nutritionist_cannot_edit_or_delete_a_patients_log(): void
    {
        $log = $this->offPlanLog();
        $nutritionist = $this->auth(User::find($this->subscriber->nutritionist_id));

        $this->patchJson("/api/v1/me/meal-logs/{$log->id}", ['quantity_grams' => 120], $nutritionist)->assertForbidden();
        $this->deleteJson("/api/v1/me/meal-logs/{$log->id}", [], $nutritionist)->assertForbidden();
        $this->assertNotNull($log->fresh());
    }

    public function test_an_archived_patient_gets_follow_up_ended(): void
    {
        $log = $this->offPlanLog();
        $token = $this->auth();
        $this->subscriber->forceFill(['archived_at' => now()])->save();

        $this->patchJson("/api/v1/me/meal-logs/{$log->id}", ['quantity_grams' => 120], $token)->assertForbidden()->assertJsonPath('code', 'follow_up_ended');
        $this->deleteJson("/api/v1/me/meal-logs/{$log->id}", [], $token)->assertForbidden()->assertJsonPath('code', 'follow_up_ended');
        $this->assertNotNull($log->fresh());
    }

    // ---- POST: replays and last_logged_at (BR-19 itself: LateEntryTest) ---

    private function post7(string $loggedAt, array $extra = [])
    {
        return $this->postJson('/api/v1/me/meal-logs', [
            'food_id' => Food::factory()->create()->id, 'quantity_grams' => 100, 'meal_type' => 'lunch', 'logged_at' => $loggedAt,
        ] + $extra, $this->auth());
    }

    public function test_a_replayed_entry_that_is_now_too_old_still_returns_the_saved_log(): void
    {
        $key = (string) Str::uuid();
        $saved = $this->offPlanLog(['idempotency_key' => $key, 'logged_at' => now()->subDays(8)]);

        $this->post7(now()->subDays(8)->toIso8601String(), ['idempotency_key' => $key])
            ->assertOk()->assertJsonPath('id', $saved->id);
    }

    public function test_an_old_entry_synced_late_does_not_move_last_logged_at_backwards(): void
    {
        $newest = now()->subHour()->startOfSecond();
        $this->offPlanLog(['logged_at' => $newest]);
        $this->subscriber->forceFill(['last_logged_at' => $newest])->save();

        $this->post7(now()->subDays(3)->toIso8601String())->assertCreated();

        $this->assertEquals($newest->timestamp, $this->subscriber->fresh()->last_logged_at->timestamp);
    }
}
