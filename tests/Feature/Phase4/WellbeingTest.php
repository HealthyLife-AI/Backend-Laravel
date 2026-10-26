<?php

namespace Tests\Feature\Phase4;

use App\Models\BodyCompositionReading;
use App\Models\PatientGoal;
use App\Models\PatientWellbeingLog;
use App\Models\Subscriber;
use App\Models\User;
use App\Models\WeightInsight;
use App\Services\Progress\WeightInsightService;
use App\Services\Wellbeing\WellbeingService;
use App\Support\ClinicDay;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/** Phase 4, C: daily sleep / stress / mood. Days are the clinic's; today is 2026-10-26. */
class WellbeingTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    private User $client;

    private Subscriber $subscriber;

    private array $nutritionistToken;

    private array $clientToken;

    private array $strangerToken;

    private array $otherToken;

    private Subscriber $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->client = User::factory()->client($this->nutritionist)->create();
        $this->subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $this->client->id]);
        $otherUser = User::factory()->client($this->nutritionist)->create();
        $this->other = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $otherUser->id]);

        $this->nutritionistToken = $this->bearerFor($this->nutritionist);
        $this->clientToken = $this->bearerFor($this->client);
        $this->strangerToken = $this->bearerFor(User::factory()->nutritionist()->create());
        $this->otherToken = $this->bearerFor($otherUser);

        $this->travelTo(CarbonImmutable::parse('2026-10-26 10:00', config('scheduling.timezone')));
    }

    private function day(int $ago = 0): string
    {
        return ClinicDay::today()->subDays($ago)->toDateString();
    }

    private function save(string $date, array $body, ?array $token = null)
    {
        return $this->putJson("/api/v1/me/wellbeing/{$date}", $body, $token ?? $this->clientToken);
    }

    private function log(int $ago, array $values, ?Subscriber $s = null): PatientWellbeingLog
    {
        return PatientWellbeingLog::create(['subscriber_id' => ($s ?? $this->subscriber)->id, 'log_date' => $this->day($ago)] + $values);
    }

    // ---- upsert ------------------------------------------------------------------------------------

    public function test_upsert_creates_then_replaces_the_day_as_a_whole(): void
    {
        $this->save($this->day(), ['sleep_hours' => 6.5, 'sleep_quality' => 'ok', 'stress' => 'high', 'mood' => 'low', 'note' => 'تعب'])->assertSuccessful()
            ->assertExactJson(['date' => $this->day(), 'sleep_hours' => 6.5, 'sleep_quality' => 'ok', 'stress' => 'high', 'mood' => 'low', 'note' => 'تعب']);

        $this->save($this->day(), ['mood' => 'good'])->assertSuccessful()
            ->assertExactJson(['date' => $this->day(), 'sleep_hours' => null, 'sleep_quality' => null, 'stress' => null, 'mood' => 'good', 'note' => null]);

        $this->assertSame(1, PatientWellbeingLog::count(), 'one row per day');
    }

    public function test_today_and_seven_days_back_are_allowed_the_eighth_and_the_future_are_not(): void
    {
        $this->save($this->day(0), ['mood' => 'ok'])->assertSuccessful();
        $this->save($this->day(7), ['mood' => 'ok'])->assertSuccessful();
        $this->save($this->day(8), ['mood' => 'ok'])->assertStatus(422)->assertJsonPath('code', 'wellbeing_date_too_old');
        $this->save(ClinicDay::today()->addDay()->toDateString(), ['mood' => 'ok'])->assertStatus(422)->assertJsonPath('code', 'wellbeing_date_future');
        $this->assertSame(2, PatientWellbeingLog::count());
    }

    public function test_at_least_one_field_and_the_value_rules(): void
    {
        $this->save($this->day(), [])->assertStatus(422);
        $this->save($this->day(), ['sleep_hours' => null, 'mood' => null])->assertStatus(422);
        $this->save($this->day(), ['sleep_hours' => 16])->assertSuccessful();
        $this->save($this->day(), ['sleep_hours' => 0])->assertSuccessful();
        $this->save($this->day(), ['sleep_hours' => 16.1])->assertStatus(422)->assertJsonValidationErrors('sleep_hours');
        $this->save($this->day(), ['sleep_hours' => -1])->assertStatus(422);
        $this->save($this->day(), ['sleep_hours' => 6.25])->assertStatus(422);
        $this->save($this->day(), ['sleep_quality' => 'great'])->assertStatus(422)->assertJsonValidationErrors('sleep_quality');
        $this->save($this->day(), ['stress' => 'huge'])->assertStatus(422);
        $this->save($this->day(), ['mood' => 'sad'])->assertStatus(422);
        $this->save($this->day(), ['note' => str_repeat('ن', 281)])->assertStatus(422);
        $this->save($this->day(), ['note' => str_repeat('ن', 280)])->assertSuccessful();
        $this->save('not-a-date', ['mood' => 'ok'])->assertNotFound();
        $this->save('2026-13-45', ['mood' => 'ok'])->assertStatus(422);
    }

    public function test_delete_removes_the_day_and_is_own_data_only(): void
    {
        $this->save($this->day(), ['mood' => 'ok'])->assertSuccessful();

        $this->deleteJson("/api/v1/me/wellbeing/{$this->day()}", [], $this->otherToken)->assertNotFound();
        $this->assertSame(1, PatientWellbeingLog::count());

        $this->deleteJson("/api/v1/me/wellbeing/{$this->day()}", [], $this->clientToken)->assertNoContent();
        $this->deleteJson("/api/v1/me/wellbeing/{$this->day()}", [], $this->clientToken)->assertNotFound();
        $this->assertSame(0, PatientWellbeingLog::count());
    }

    // ---- listing ---------------------------------------------------------------------------------------

    public function test_the_list_is_oldest_first_own_only_and_default_14_days(): void
    {
        $this->log(20, ['mood' => 'low']);
        $this->log(3, ['mood' => 'ok']);
        $this->log(1, ['mood' => 'good']);
        $this->log(1, ['mood' => 'low'], $this->other);

        $list = $this->getJson('/api/v1/me/wellbeing', $this->clientToken)->assertOk()->json();

        $this->assertSame([$this->day(3), $this->day(1)], array_column($list, 'date'));
        $this->assertSame(['date', 'sleep_hours', 'sleep_quality', 'stress', 'mood', 'note'], array_keys($list[0]));

        $this->assertCount(3, $this->getJson("/api/v1/me/wellbeing?from={$this->day(30)}&to={$this->day()}", $this->clientToken)->json());
    }

    public function test_a_range_is_at_most_60_days_and_must_be_ordered(): void
    {
        $this->getJson("/api/v1/me/wellbeing?from={$this->day(59)}&to={$this->day()}", $this->clientToken)->assertOk();
        $this->getJson("/api/v1/me/wellbeing?from={$this->day(60)}&to={$this->day()}", $this->clientToken)->assertStatus(422)->assertJsonPath('code', 'wellbeing_range_too_long');
        $this->getJson("/api/v1/me/wellbeing?from={$this->day()}&to={$this->day(5)}", $this->clientToken)->assertStatus(422)->assertJsonPath('code', 'wellbeing_range_invalid');
        $this->getJson('/api/v1/me/wellbeing?from=yesterday', $this->clientToken)->assertStatus(422);
    }

    // ---- nutritionist view + summary math -----------------------------------------------------------------

    public function test_the_summary_math(): void
    {
        $this->log(0, ['sleep_hours' => 5.5, 'stress' => 'high', 'mood' => 'low']);
        $this->log(1, ['sleep_hours' => 6.0, 'stress' => 'high', 'mood' => 'ok']);   // 6.0 is not under 6
        $this->log(2, ['sleep_hours' => 4.0, 'stress' => 'low', 'mood' => 'ok']);
        $this->log(3, ['sleep_hours' => 8.0, 'stress' => 'low', 'mood' => 'good']);
        $this->log(4, ['mood' => 'good']);                                              // no sleep figure
        $this->log(13, ['sleep_hours' => 7.5]);                                        // the 14th day: inside
        $this->log(14, ['sleep_hours' => 1.0]);                                        // outside

        $summary = $this->getJson("/api/v1/clients/{$this->subscriber->id}/wellbeing", $this->nutritionistToken)->assertOk()->json('summary');

        $this->assertSame($this->day(13), $summary['from']);
        $this->assertSame($this->day(), $summary['to']);
        $this->assertSame(14, $summary['days']);
        $this->assertSame(6, $summary['logged_days']);
        $this->assertSame(5, $summary['sleep_logged_nights']);
        $this->assertEquals(6.2, $summary['avg_sleep_hours'], '(5.5+6+4+8+7.5)/5');
        $this->assertSame(2, $summary['nights_under_6h']);
        $this->assertSame('high', $summary['top_stress'], 'a tie goes to the worse one');
        $this->assertSame('ok', $summary['top_mood'], 'ok x2, good x2, low x1: the tie goes to the worse of the two');
    }

    public function test_an_empty_summary_has_nulls_not_zeros_for_the_averages(): void
    {
        $summary = $this->getJson("/api/v1/clients/{$this->subscriber->id}/wellbeing", $this->nutritionistToken)->assertOk()->json('summary');

        $this->assertSame([0, 0, null, 0, null, null], [$summary['logged_days'], $summary['sleep_logged_nights'], $summary['avg_sleep_hours'], $summary['nights_under_6h'], $summary['top_stress'], $summary['top_mood']]);
    }

    public function test_the_nutritionist_view_returns_the_logs_and_is_for_the_own_nutritionist_only(): void
    {
        $this->log(2, ['mood' => 'ok']);
        $url = "/api/v1/clients/{$this->subscriber->id}/wellbeing";

        $this->getJson($url, $this->nutritionistToken)->assertOk()->assertJsonPath('logs.0.mood', 'ok');
        $this->getJson($url, $this->strangerToken)->assertNotFound();
        $this->getJson($url, $this->clientToken)->assertForbidden();
        $this->getJson('/api/v1/me/wellbeing', $this->nutritionistToken)->assertForbidden();
    }

    // ---- weight insight: short_sleep -------------------------------------------------------------------------------

    private function windowReadings(): void
    {
        PatientGoal::create(['subscriber_id' => $this->subscriber->id, 'goal_type' => 'weight_loss']);
        BodyCompositionReading::create(['subscriber_id' => $this->subscriber->id, 'recorded_at' => $this->day(14), 'source' => 'clinic-analyser', 'weight_kg' => 80]);
        BodyCompositionReading::create(['subscriber_id' => $this->subscriber->id, 'recorded_at' => $this->day(0), 'source' => 'clinic-analyser', 'weight_kg' => 79]);
    }

    private function shortSleep(): bool
    {
        return app(WeightInsightService::class)->facts($this->subscriber)['facts']['short_sleep'];
    }

    public function test_short_sleep_needs_three_nights_under_six_hours_inside_the_window(): void
    {
        $this->windowReadings();
        $this->assertFalse($this->shortSleep());

        $this->log(2, ['sleep_hours' => 5.9]);
        $this->log(3, ['sleep_hours' => 4.0]);
        $this->log(4, ['sleep_hours' => 6.0]);   // not under
        $this->log(5, ['mood' => 'low']);          // no sleep figure
        $this->assertFalse($this->shortSleep(), 'two short nights');

        $this->log(6, ['sleep_hours' => 5.0]);
        $this->assertTrue($this->shortSleep(), 'three');
    }

    public function test_nights_outside_the_window_do_not_count(): void
    {
        $this->windowReadings();   // the window is the 14 days after the first weighing: days ago 13..0
        $this->log(14, ['sleep_hours' => 3.0]);   // the day of the first weighing: outside
        $this->log(20, ['sleep_hours' => 3.0]);
        $this->log(21, ['sleep_hours' => 3.0]);

        $this->assertFalse($this->shortSleep());
    }

    public function test_only_a_boolean_reaches_the_model_and_the_version_moved(): void
    {
        $this->assertSame(3, config('weight_insight.text_version'), 'bumped for the new fact');

        $this->windowReadings();
        foreach ([2, 3, 4] as $d) {
            $this->log($d, ['sleep_hours' => 4.5, 'note' => 'ملاحظة-نوم-سرية']);
        }
        config(['ai.base_url' => 'https://fake-llm.test', 'ai.api_key' => 'k', 'ai.model' => 'm']);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => json_encode(['text' => 'نومك كان قليل. وأخصائيتك بتتابع معك.'])]]]])]);

        $this->getJson("/api/v1/clients/{$this->subscriber->id}/weight-insight", $this->nutritionistToken)->assertOk()->assertJsonPath('insight.facts.short_sleep', true);

        Http::assertSent(function ($request): bool {
            $facts = json_decode($request['messages'][1]['content'], true);
            $everything = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return $facts['short_sleep'] === true
                && ! str_contains($everything, 'sleep_hours') && ! str_contains($everything, 'ملاحظة-نوم') && ! str_contains($everything, '4.5')
                && str_contains($request['messages'][0]['content'], 'never hours or a number of nights');
        });
    }

    public function test_an_insight_stored_before_the_version_bump_is_regenerated(): void
    {
        $this->windowReadings();
        config(['ai.base_url' => '', 'ai.api_key' => '']);
        $this->getJson("/api/v1/clients/{$this->subscriber->id}/weight-insight", $this->nutritionistToken)->assertOk();
        WeightInsight::query()->update(['text_version' => 2, 'text_ar' => 'قديم']);

        $this->getJson("/api/v1/clients/{$this->subscriber->id}/weight-insight", $this->nutritionistToken)->assertOk()->assertJsonPath('insight.text_ar', fn ($t) => $t !== 'قديم');
        $this->assertSame(3, (int) WeightInsight::first()->text_version);
    }

    // ---- morning reminder ----------------------------------------------------------------------------------------------

    private function reminders(): array
    {
        return array_column($this->getJson('/api/v1/me/today', $this->clientToken)->assertOk()->json('reminders'), 'text_ar', 'type');
    }

    public function test_the_sleep_question_is_asked_only_of_patients_who_logged_in_the_last_14_days(): void
    {
        $this->assertArrayNotHasKey('wellbeing', $this->reminders(), 'a new user is not nagged');

        $this->log(14, ['mood' => 'ok']);
        $this->assertSame('كيف كان نومك مبارح؟', $this->reminders()['wellbeing'], 'logged 14 days ago');

        PatientWellbeingLog::query()->delete();
        $this->log(15, ['mood' => 'ok']);
        $this->assertArrayNotHasKey('wellbeing', $this->reminders(), 'logged 15 days ago: lapsed');
    }

    public function test_it_stops_once_today_is_logged_and_is_one_reminder_only(): void
    {
        $this->log(3, ['mood' => 'ok']);
        $types = array_keys($this->reminders());
        $this->assertSame(1, count(array_keys($types, 'wellbeing')));

        $this->save($this->day(), ['sleep_hours' => 7])->assertSuccessful();
        $this->assertArrayNotHasKey('wellbeing', $this->reminders());
    }

    public function test_another_patients_log_does_not_count(): void
    {
        $this->log(1, ['mood' => 'ok'], $this->other);

        $this->assertArrayNotHasKey('wellbeing', $this->reminders());
    }

    public function test_the_service_numbers_are_the_documented_ones(): void
    {
        $this->assertSame(7, PatientWellbeingLog::MAX_AGE_DAYS);
        $this->assertSame(60, PatientWellbeingLog::MAX_RANGE_DAYS);
        $this->assertSame(14, PatientWellbeingLog::SUMMARY_DAYS);
        $this->assertSame(6.0, app(WellbeingService::class)->shortSleepHours());
    }
}
