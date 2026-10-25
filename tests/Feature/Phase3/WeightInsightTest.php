<?php

namespace Tests\Feature\Phase3;

use App\Models\BodyCompositionReading;
use App\Models\Food;
use App\Models\MealLog;
use App\Models\PatientCheckin;
use App\Models\PatientCondition;
use App\Models\PatientGoal;
use App\Models\PatientMedication;
use App\Models\Subscriber;
use App\Models\User;
use App\Models\WeightInsight;
use App\Services\Progress\WeightInsightService;
use App\Support\ClinicDay;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * Phase 3, C: the weight-change insight. The facts are the server's own
 * arithmetic; the model only words them and is never trusted with a number.
 * "Today" is 2026-10-26.
 */
class WeightInsightTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    private User $client;

    private Subscriber $subscriber;

    private array $nutritionistToken;

    private array $clientToken;

    private array $strangerToken;

    private Subscriber $otherSubscriber;

    private array $otherClientToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['ai.base_url' => 'https://fake-llm.test', 'ai.api_key' => 'test-key', 'ai.model' => 'test-model']);

        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->client = User::factory()->client($this->nutritionist)->create(['name' => 'سلمى الفريدة', 'phone' => '0599123456']);
        $this->subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $this->client->id]);
        PatientGoal::create(['subscriber_id' => $this->subscriber->id, 'goal_type' => 'weight_loss']);

        // Sensitive things that must never reach the model.
        PatientCondition::create(['subscriber_id' => $this->subscriber->id, 'code' => 'hypertension', 'note' => 'ملاحظة سرية عن الحالة', 'created_by' => $this->nutritionist->id]);
        PatientMedication::create(['subscriber_id' => $this->subscriber->id, 'name' => 'دواء-خاص-جدًا', 'dose' => '5mg']);

        $this->nutritionistToken = $this->bearerFor($this->nutritionist);
        $this->clientToken = $this->bearerFor($this->client);
        $this->strangerToken = $this->bearerFor(User::factory()->nutritionist()->create());

        // A second patient; tokens are issued before time is moved (the JWT check reads the real clock).
        $otherUser = User::factory()->client($this->nutritionist)->create();
        $this->otherSubscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $otherUser->id]);
        $this->otherClientToken = $this->bearerFor($otherUser);

        $this->travelTo(CarbonImmutable::parse('2026-10-26 10:00', config('scheduling.timezone')));
    }

    // ---- helpers ------------------------------------------------------------------------------------

    private function day(int $ago): string
    {
        return ClinicDay::today()->subDays($ago)->toDateString();
    }

    private function reading(int $daysAgo, float $kg): BodyCompositionReading
    {
        return BodyCompositionReading::create(['subscriber_id' => $this->subscriber->id, 'recorded_at' => $this->day($daysAgo), 'source' => 'clinic-analyser', 'weight_kg' => $kg]);
    }

    private function log(int $daysAgo, string $kind = 'planned', ?Food $food = null): MealLog
    {
        return MealLog::create([
            'subscriber_id' => $this->subscriber->id, 'food_id' => ($food ?? Food::factory()->create())->id, 'log_kind' => $kind,
            'meal_type' => 'lunch', 'quantity_grams' => 100, 'logged_at' => CarbonImmutable::parse($this->day($daysAgo).' 12:00', config('scheduling.timezone')),
        ]);
    }

    private function salty(): Food
    {
        return Food::factory()->create(['sodium_mg_per_100g' => 900]);
    }

    private function facts(): ?array
    {
        return app(WeightInsightService::class)->facts($this->subscriber);
    }

    private function reply(string $text): array
    {
        return ['choices' => [['message' => ['content' => json_encode(['text' => $text])]]]];
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/clients/{$this->subscriber->id}/weight-insight{$suffix}";
    }

    /** A 14-day window with a 1.2 kg loss and a plausible LLM sentence. */
    private function fourteenDays(): void
    {
        $this->reading(14, 80.0);
        $this->reading(0, 78.8);
    }

    // ---- the window ---------------------------------------------------------------------------------

    public function test_the_window_is_between_the_two_latest_readings(): void
    {
        $this->reading(30, 90.0);
        $this->reading(14, 80.0);
        $this->reading(0, 78.8);

        $computed = $this->facts();

        $this->assertSame([$this->day(14), $this->day(0)], [$computed['from_date'], $computed['to_date']]);
        $this->assertEquals(-1.2, $computed['facts']['change_kg']);
        $this->assertSame(14, $computed['facts']['days']);
    }

    public function test_a_close_latest_pair_reaches_back_to_a_reading_at_least_five_days_earlier(): void
    {
        $this->reading(20, 82.0);
        $this->reading(8, 80.0);
        $this->reading(3, 79.5);   // only 2 days before the latest
        $this->reading(1, 79.0);

        $computed = $this->facts();

        // 3 days ago is 2 days before the latest (too close); 8 days ago is the nearest one 5+ days back.
        $this->assertSame($this->day(8), $computed['from_date']);
        $this->assertSame($this->day(1), $computed['to_date']);
        $this->assertEquals(-1.0, $computed['facts']['change_kg']);
        $this->assertSame(7, $computed['facts']['days']);
    }

    public function test_exactly_five_days_is_enough(): void
    {
        $this->reading(5, 80.0);
        $this->reading(0, 79.0);

        $this->assertSame(5, $this->facts()['facts']['days']);
    }

    public function test_there_is_no_insight_without_two_readings_or_a_five_day_gap_or_within_28_days(): void
    {
        $this->assertNull($this->facts(), 'no readings');

        $this->reading(0, 80.0);
        $this->assertNull($this->facts(), 'one reading');

        $this->reading(3, 81.0);
        $this->assertNull($this->facts(), 'only 3 days apart and nothing earlier');

        $this->reading(40, 85.0);
        $this->assertNull($this->facts(), 'the nearest reading 5+ days back is 40 days away: too old to explain');
    }

    public function test_twenty_eight_days_is_the_upper_edge(): void
    {
        $this->reading(28, 80.0);
        $this->reading(0, 79.0);
        $this->assertSame(28, $this->facts()['facts']['days']);

        BodyCompositionReading::query()->delete();
        $this->reading(29, 80.0);
        $this->reading(0, 79.0);
        $this->assertNull($this->facts());
    }

    public function test_two_readings_on_one_day_count_once_the_last_one_entered(): void
    {
        $this->reading(10, 80.0);
        $this->reading(0, 70.0);   // a typo ...
        $this->reading(0, 79.0);   // ... corrected the same day

        $this->assertEquals(-1.0, $this->facts()['facts']['change_kg']);
    }

    // ---- the facts ------------------------------------------------------------------------------------

    public function test_adherence_logged_days_off_plan_and_high_sodium_are_counted_inside_the_window_only(): void
    {
        $this->fourteenDays();
        $salty = $this->salty();
        $plain = Food::factory()->create(['sodium_mg_per_100g' => 100]);

        $this->log(10, 'planned', $plain);
        $this->log(10, 'planned', $salty);
        $this->log(7, 'alternative', $plain);
        $this->log(5, 'off_plan', $salty);
        $this->log(2, 'off_plan', $plain);
        $this->log(20, 'off_plan', $salty);   // outside

        $facts = $this->facts()['facts'];

        $this->assertSame(4, $facts['logged_days'], 'days 10, 7, 5 and 2');
        $this->assertSame(2, $facts['off_plan_meals']);
        $this->assertSame(2, $facts['high_sodium_logs'], 'the salty food twice inside the window');
        $this->assertEquals(60.0, $facts['adherence_percent'], '3 of 5 logs on plan');
    }

    public function test_no_logs_means_no_adherence_and_no_consistency_claim(): void
    {
        $this->fourteenDays();

        $facts = $this->facts()['facts'];

        $this->assertNull($facts['adherence_percent']);
        $this->assertSame(0, $facts['logged_days']);
        $this->assertFalse($facts['consistent_with_plan']);
    }

    public function test_the_high_sodium_threshold_is_the_nutrition_config(): void
    {
        $this->fourteenDays();
        $this->log(5, 'planned', Food::factory()->create(['sodium_mg_per_100g' => 599]));
        $this->assertSame(0, $this->facts()['facts']['high_sodium_logs']);

        $this->log(5, 'planned', Food::factory()->create(['sodium_mg_per_100g' => 600]));
        $this->assertSame(1, $this->facts()['facts']['high_sodium_logs'], 'at the threshold counts');
    }

    // ---- the booleans ------------------------------------------------------------------------------

    public function test_a_small_change_over_a_week_or_less_is_likely_water(): void
    {
        $this->reading(7, 80.0);
        $this->reading(0, 79.5);
        $this->assertTrue($this->facts()['facts']['likely_water_fluctuation']);

        BodyCompositionReading::query()->delete();
        $this->reading(7, 80.0);
        $this->reading(0, 79.0);   // exactly 1.0 kg: not "under"
        $this->assertFalse($this->facts()['facts']['likely_water_fluctuation']);

        BodyCompositionReading::query()->delete();
        $this->reading(8, 80.0);
        $this->reading(0, 79.8);   // small but over a week
        $this->assertFalse($this->facts()['facts']['likely_water_fluctuation']);
    }

    public function test_three_high_sodium_logs_also_suggest_water_whatever_the_change(): void
    {
        $this->reading(20, 85.0);
        $this->reading(0, 80.0);
        $salty = $this->salty();

        $this->log(10, 'planned', $salty);
        $this->log(9, 'planned', $salty);
        $this->assertFalse($this->facts()['facts']['likely_water_fluctuation']);

        $this->log(8, 'planned', $salty);
        $this->assertTrue($this->facts()['facts']['likely_water_fluctuation']);
    }

    public function test_consistent_with_plan_needs_the_right_direction_and_adherence_of_70(): void
    {
        $this->fourteenDays();   // weight loss goal, -1.2 kg
        foreach ([13, 12, 11, 10, 9, 8, 7] as $d) {
            $this->log($d, 'planned');
        }
        $this->log(6, 'planned');
        $this->log(5, 'planned');
        $this->log(4, 'off_plan');   // 9 planned of 10 logs = 90 %
        $this->assertTrue($this->facts()['facts']['consistent_with_plan']);

        // Wrong direction for the goal.
        BodyCompositionReading::query()->delete();
        $this->reading(14, 78.0);
        $this->reading(0, 79.5);
        $this->assertFalse($this->facts()['facts']['consistent_with_plan']);
    }

    public function test_seventy_percent_is_the_edge(): void
    {
        $this->fourteenDays();
        foreach ([13, 12, 11, 10, 9, 8, 7] as $d) {
            $this->log($d, 'planned');
        }
        foreach ([6, 5, 4] as $d) {
            $this->log($d, 'off_plan');
        }
        $this->assertEquals(70.0, $this->facts()['facts']['adherence_percent']);
        $this->assertTrue($this->facts()['facts']['consistent_with_plan'], '70 is enough');

        $this->log(3, 'off_plan');   // 7 of 11 = 63.6
        $this->assertFalse($this->facts()['facts']['consistent_with_plan']);
    }

    public function test_the_goal_decides_which_direction_matches(): void
    {
        $this->reading(14, 70.0);
        $this->reading(0, 71.0);
        foreach ([13, 12, 11, 10] as $d) {
            $this->log($d, 'planned');
        }

        $this->assertFalse($this->facts()['facts']['consistent_with_plan'], 'gained on a weight-loss goal');

        PatientGoal::where('subscriber_id', $this->subscriber->id)->update(['goal_type' => 'weight_gain']);
        $this->subscriber->unsetRelation('patientGoal');
        $this->assertTrue($this->facts()['facts']['consistent_with_plan'], 'gained on a gain goal');

        PatientGoal::where('subscriber_id', $this->subscriber->id)->update(['goal_type' => 'weight_maintenance']);
        $this->subscriber->unsetRelation('patientGoal');
        $this->assertFalse($this->facts()['facts']['consistent_with_plan'], '1.0 kg is not "stable"');

        PatientGoal::where('subscriber_id', $this->subscriber->id)->update(['goal_type' => 'health_energy']);
        $this->subscriber->unsetRelation('patientGoal');
        $this->assertFalse($this->facts()['facts']['consistent_with_plan'], 'no direction to match');
    }

    // ---- check-in signals ---------------------------------------------------------------------------------

    private function checkin(int $daysAgo, array $answers): PatientCheckin
    {
        return PatientCheckin::create([
            'subscriber_id' => $this->subscriber->id, 'due_on' => $this->day($daysAgo), 'status' => 'submitted', 'answers' => $answers,
            'submitted_at' => CarbonImmutable::parse($this->day($daysAgo).' 12:00', config('scheduling.timezone')),
        ]);
    }

    public function test_checkin_signals_come_from_answers_inside_the_window(): void
    {
        $this->fourteenDays();
        $this->assertNull($this->facts()['facts']['checkins'], 'none answered');

        $this->checkin(30, ['appetite' => 'higher', 'sleep' => 'short', 'barriers' => ['budget']]);   // outside
        $this->assertNull($this->facts()['facts']['checkins']);

        $this->checkin(10, ['appetite' => 'lower', 'sleep' => 'good', 'barriers' => ['time', 'hunger'], 'note' => 'ملاحظة حرة']);
        $this->checkin(3, ['appetite' => 'higher', 'sleep' => 'interrupted', 'barriers' => ['hunger', 'mood', 'none'], 'note' => 'أخرى']);

        $this->assertSame(
            ['appetite' => 'higher', 'sleep' => 'interrupted', 'barriers' => ['hunger', 'mood']],
            $this->facts()['facts']['checkins'],
            'the latest appetite and sleep; the most-named barriers (a tie goes to the more recent), "none" never',
        );
    }

    public function test_the_free_text_note_is_not_among_the_facts(): void
    {
        $this->fourteenDays();
        $this->checkin(3, ['appetite' => 'normal', 'sleep' => 'good', 'barriers' => ['time'], 'note' => 'نص-حر-خاص']);

        $this->assertStringNotContainsString('نص-حر-خاص', json_encode($this->facts(), JSON_UNESCAPED_UNICODE));
    }

    // ---- what the model sees ----------------------------------------------------------------------------------

    public function test_only_the_facts_are_sent_to_the_model(): void
    {
        $this->fourteenDays();
        $this->checkin(3, ['appetite' => 'normal', 'sleep' => 'good', 'barriers' => ['time'], 'note' => 'نص-حر-خاص']);
        $this->subscriber->forceFill(['code' => 'CODE-9999'])->save();
        Http::fake(['*' => Http::response($this->reply('نزل وزنك شوي، وأكلك ماشي. وأخصائيتك بتتابع معك.'))]);

        $this->getJson($this->url(), $this->nutritionistToken)->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $messages = $request['messages'];
            $everything = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            foreach (['سلمى', 'الفريدة', '0599123456', $this->client->email, 'CODE-9999', 'hypertension', 'ضغط', 'ملاحظة سرية', 'دواء-خاص', '5mg', 'نص-حر-خاص'] as $secret) {
                if (str_contains($everything, $secret)) {
                    return false;
                }
            }

            $facts = json_decode($messages[1]['content'], true);

            return count($messages) === 2
                && $messages[0]['role'] === 'system' && $messages[1]['role'] === 'user'
                && array_keys($facts) === ['change_kg', 'days', 'adherence_percent', 'logged_days', 'off_plan_meals', 'high_sodium_logs', 'checkins', 'likely_water_fluctuation', 'consistent_with_plan']
                && $facts['change_kg'] === -1.2 && $facts['days'] === 14;
        });
    }

    // ---- what comes back --------------------------------------------------------------------------------------------

    private function insightFor(string $text): array
    {
        $this->fourteenDays();
        Http::fake(['*' => Http::response($this->reply($text))]);

        return $this->getJson($this->url(), $this->nutritionistToken)->assertOk()->json('insight');
    }

    public function test_a_good_reply_is_kept_and_not_marked_as_automatic(): void
    {
        $text = 'نزل وزنك حوالي 1.2 كغ خلال 14 يوم، وأكلك ماشي مع الخطة. وأخصائيتك بتتابع معك.';

        $insight = $this->insightFor($text);

        $this->assertSame($text, $insight['text_ar']);
        $this->assertFalse($insight['is_fallback']);
    }

    public function test_the_closing_sentence_is_added_when_the_model_leaves_it_out(): void
    {
        $insight = $this->insightFor('نزل وزنك شوي وأكلك ماشي.');

        $this->assertSame('نزل وزنك شوي وأكلك ماشي. وأخصائيتك بتتابع معك.', $insight['text_ar']);
        $this->assertFalse($insight['is_fallback']);
    }

    /** @return array<string, array{string}> */
    public static function rejectedReplies(): array
    {
        return [
            'a number that is not in the facts' => ['نزل وزنك 3 كيلو وأخصائيتك بتتابع معك.'],
            'an Arabic-Indic number that is not in the facts' => ['نزل وزنك ٧ كيلو. وأخصائيتك بتتابع معك.'],
            'a calorie figure' => ['خفّف 500 من أكلك. وأخصائيتك بتتابع معك.'],
            'longer than 350 characters' => [str_repeat('كلام طويل ', 40).'وأخصائيتك بتتابع معك.'],
            'the word medicine' => ['يمكن بسبب دواء جديد. وأخصائيتك بتتابع معك.'],
            'the word disease' => ['ممكن يكون مرض. وأخصائيتك بتتابع معك.'],
            'the word diabetes' => ['انتبه من السكري. وأخصائيتك بتتابع معك.'],
            'the word pressure' => ['الضغط يؤثر. وأخصائيتك بتتابع معك.'],
            'the word diagnosis' => ['هذا تشخيص واضح. وأخصائيتك بتتابع معك.'],
            'the word calorie' => ['قلّل السعرات شوي. وأخصائيتك بتتابع معك.'],
            'nothing' => [''],
            'only spaces' => ['   '],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedReplies')]
    public function test_a_bad_reply_is_replaced_by_the_fallback(string $reply): void
    {
        $insight = $this->insightFor($reply);

        $this->assertTrue($insight['is_fallback']);
        $this->assertNotSame($reply, $insight['text_ar']);
        $this->assertSame(app(WeightInsightService::class)->fallbackText($insight['facts']), $insight['text_ar']);
    }

    public function test_the_numbers_of_the_facts_are_allowed_in_the_reply(): void
    {
        // 1.2 and 14 are facts; 12 would not be.
        $this->assertFalse($this->insightFor('نزل 1.2 كغ بـ 14 يوم. وأخصائيتك بتتابع معك.')['is_fallback']);
    }

    public function test_exactly_350_characters_pass_and_351_do_not(): void
    {
        $closing = config('weight_insight.closing_ar');
        $fill = fn (int $total) => str_repeat('ك', $total - mb_strlen($closing) - 1).' '.$closing;

        $service = app(WeightInsightService::class);
        $facts = ['change_kg' => -1.2, 'days' => 14];

        $this->assertNotNull($service->accepted($fill(350), $facts));
        $this->assertNull($service->accepted($fill(351), $facts));
    }

    public function test_a_provider_failure_or_no_provider_falls_back_without_error(): void
    {
        $this->fourteenDays();
        Http::fake(['*' => Http::response(null, 500)]);
        $this->getJson($this->url(), $this->nutritionistToken)->assertOk()->assertJsonPath('insight.is_fallback', true);

        WeightInsight::query()->delete();
        config(['ai.base_url' => '', 'ai.api_key' => '']);
        Http::fake();
        $this->getJson($this->url(), $this->nutritionistToken)->assertOk()->assertJsonPath('insight.is_fallback', true);
        Http::assertNothingSent();
    }

    // ---- why it fell back ---------------------------------------------------------------------------

    /** @return array<string, array{string, string}> reply => expected reason */
    public static function rejectionReasons(): array
    {
        return [
            'empty' => ['', 'empty'],
            'a number' => ['نزل وزنك 3 كيلو. وأخصائيتك بتتابع معك.', 'rejected_digit'],
            'only spaces' => ['   ', 'empty'],
            'a blocked word' => ['ممكن بسبب دواء جديد. وأخصائيتك بتتابع معك.', 'rejected_blocklist:دواء'],
            'the same word with the article' => ['انتبه من السكر. وأخصائيتك بتتابع معك.', 'rejected_blocklist:سكر'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectionReasons')]
    public function test_the_reason_for_the_fallback_is_stored_and_shown_to_the_nutritionist(string $reply, string $reason): void
    {
        $insight = $this->insightFor($reply);

        $this->assertSame($reason, $insight['fallback_reason']);
        $this->assertSame($reason, WeightInsight::first()->fallback_reason);
    }

    public function test_a_long_reply_has_its_own_reason(): void
    {
        $insight = $this->insightFor(str_repeat('كلام طويل ', 40));

        $this->assertSame('rejected_length', $insight['fallback_reason']);
    }

    public function test_provider_failure_and_no_provider_have_their_reasons(): void
    {
        $this->fourteenDays();
        Http::fake(['*' => Http::response(null, 500)]);
        $this->getJson($this->url(), $this->nutritionistToken)->assertJsonPath('insight.fallback_reason', 'provider_failed');

        WeightInsight::query()->delete();
        config(['ai.base_url' => '', 'ai.api_key' => '']);
        $this->getJson($this->url(), $this->nutritionistToken)->assertJsonPath('insight.fallback_reason', 'not_configured');
    }

    public function test_a_model_text_has_no_reason(): void
    {
        $insight = $this->insightFor('نزل وزنك شوي. وأخصائيتك بتتابع معك.');

        $this->assertFalse($insight['is_fallback']);
        $this->assertNull($insight['fallback_reason']);
    }

    public function test_the_patient_never_sees_the_reason(): void
    {
        $this->fourteenDays();
        $this->subscriber->forceFill(['show_weight_insight' => true])->save();
        Http::fake(['*' => Http::response(null, 500)]);

        $response = $this->getJson('/api/v1/me/weight-insight', $this->clientToken)->assertOk();

        $this->assertSame(['from_date', 'to_date', 'change_kg', 'text_ar'], array_keys($response->json()));
        $this->assertStringNotContainsString('provider_failed', $response->getContent());
    }

    public function test_the_reason_is_logged_like_the_other_ai_suggestions(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        $this->insightFor('ممكن بسبب دواء جديد. وأخصائيتك بتتابع معك.');

        \Illuminate\Support\Facades\Log::shouldHaveReceived('info')->withArgs(fn ($message, $context = []) => str_contains($message, 'Weight insight') && ($context['reason'] ?? null) === 'rejected_blocklist:دواء')->once();
    }

    // ---- the blocklist matches words, not letters ------------------------------------------------------

    /** @return array<string, array{string, bool}> text => blocked? */
    public static function blocklistCases(): array
    {
        return [
            // the bare word, the article, diacritics
            'the bare word' => ['هذا سكر', true],
            'with the article' => ['كثرة السكر', true],
            'a diabetes word' => ['مرضى السكري', true],
            'pressure as a word' => ['ارتفاع الضغط', true],
            'with diacritics' => ['السُّكَّر', true],
            'a medicine variant' => ['الأدوية', true],
            // prefixes: و/ف, then ب/ل/ك, then ال (or لل)
            'ل + ال contracted' => ['انتبه للسكري', true],
            'ب + ال' => ['اهتم بالسكري', true],
            'و + ال' => ['والضغط يتأثر', true],
            'ف + ال' => ['فالدواء مهم', true],
            'ك + ال' => ['كالدواء تمامًا', true],
            'و + ب + ال' => ['وبالضغط', true],
            'ف + ل contracted' => ['فللسكر', true],
            'و + ل without the article' => ['ولمرض', true],
            'ب without the article' => ['بدواء', true],
            // suffixes
            'ك' => ['دوائك', true],
            'ك with a vowel mark' => ['ضغطِكِ', true],
            'كم' => ['أدويتكم', true],
            'كن' => ['علاجكن', true],
            'ه' => ['ضغطه', true],
            'ها' => ['مرضها', true],
            'هم' => ['علاجهم', true],
            'هن' => ['سكرهن', true],
            'ي' => ['علاجي', true],
            'نا' => ['مرضنا', true],
            'hamza stem + suffix' => ['دوائي', true],
            'a prefix and a suffix together' => ['بدوائك', true],
            'over-blocking an ordinary use is accepted' => ['ضغط الشغل', true],
            // whole words only
            'a word that only contains it' => ['مسكرات', false],
            'a verb that only contains it' => ['اضغط هنا', false],
            'a longer word' => ['سكرتير', false],
            'a different word entirely' => ['وزنك ماشي كويس', false],
            'a suffix that is not a pronoun' => ['ضغطات', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blocklistCases')]
    public function test_the_blocklist_matches_whole_words_only(string $text, bool $blocked): void
    {
        $result = app(WeightInsightService::class)->evaluate($text.'. '.config('weight_insight.closing_ar'), ['change_kg' => -1.2, 'days' => 14]);

        $this->assertSame($blocked, $result[0] === null, $text);
        if ($blocked) {
            $this->assertStringStartsWith('rejected_blocklist:', (string) $result[1]);
        }
    }

    // ---- the window and the day counts -----------------------------------------------------------------

    public function test_every_count_covers_the_days_after_the_first_weighing_up_to_the_last(): void
    {
        $this->reading(6, 80.0);   // the window: 6 days after this one
        $this->reading(0, 79.0);

        $this->log(6, 'off_plan', $this->salty());   // the day of the first weighing: before the change
        foreach ([5, 4, 3, 2, 1, 0] as $d) {
            $this->log($d, 'planned');
        }

        $facts = $this->facts()['facts'];

        $this->assertSame(6, $facts['days']);
        $this->assertSame(6, $facts['logged_days'], 'the six days after the first weighing, not seven');
        $this->assertSame(0, $facts['off_plan_meals'], 'and the log of the first weighing day is outside');
        $this->assertSame(0, $facts['high_sodium_logs']);
    }

    public function test_logged_days_never_exceed_days(): void
    {
        $this->reading(10, 80.0);
        $this->reading(0, 79.0);

        // Several logs a day, on every day around the window.
        for ($d = 12; $d >= 0; $d--) {
            $this->log($d, 'planned');
            $this->log($d, 'off_plan');
        }

        $facts = $this->facts()['facts'];

        $this->assertSame(10, $facts['days']);
        $this->assertLessThanOrEqual($facts['days'], $facts['logged_days']);
        $this->assertSame(10, $facts['logged_days']);
    }

    // ---- the fallback texts -------------------------------------------------------------------------------------------

    public function test_the_fallback_is_chosen_by_the_booleans(): void
    {
        $service = app(WeightInsightService::class);
        $base = ['change_kg' => -1.2, 'days' => 14, 'likely_water_fluctuation' => false, 'consistent_with_plan' => false];

        $water = $service->fallbackText(['likely_water_fluctuation' => true, 'consistent_with_plan' => true] + $base);
        $consistent = $service->fallbackText(['consistent_with_plan' => true] + $base);
        $neutral = $service->fallbackText($base);

        $this->assertStringContainsString('الماء والملح', $water, 'water first, even when consistent too');
        $this->assertStringContainsString('بيمشي مع التزامك', $consistent);
        $this->assertStringNotContainsString('الماء والملح', $neutral);
        $this->assertStringNotContainsString('بيمشي مع التزامك', $neutral);
        $this->assertStringContainsString('نزل وزنك حوالي 1.2 كغ خلال 14 يومًا', $neutral);
    }

    public function test_the_fallback_counts_days_in_correct_arabic(): void
    {
        $service = app(WeightInsightService::class);
        $base = ['change_kg' => -1.0, 'likely_water_fluctuation' => false, 'consistent_with_plan' => false];

        foreach ([5 => '5 أيام', 6 => '6 أيام', 10 => '10 أيام', 11 => '11 يومًا', 14 => '14 يومًا', 28 => '28 يومًا'] as $days => $phrase) {
            $this->assertStringContainsString("خلال {$phrase}.", $service->fallbackText(['days' => $days] + $base), "{$days} days");
        }

        // 1 and 2 are not reachable by the window rules, but the text must still be right.
        $this->assertStringContainsString('خلال يوم واحد.', $service->fallbackText(['days' => 1] + $base));
        $this->assertStringContainsString('خلال يومين.', $service->fallbackText(['days' => 2] + $base));
    }

    public function test_the_fallback_says_up_down_or_steady(): void
    {
        $service = app(WeightInsightService::class);
        $base = ['days' => 9, 'likely_water_fluctuation' => false, 'consistent_with_plan' => false];

        $this->assertStringContainsString('نزل وزنك حوالي 0.4 كغ', $service->fallbackText(['change_kg' => -0.4] + $base));
        $this->assertStringContainsString('زاد وزنك حوالي 2.0 كغ', $service->fallbackText(['change_kg' => 2.0] + $base));
        $this->assertStringContainsString('وزنك ثابت تقريبًا', $service->fallbackText(['change_kg' => 0.0] + $base));
    }

    public function test_every_fallback_passes_its_own_rules(): void
    {
        $service = app(WeightInsightService::class);

        foreach ([[false, false], [true, false], [false, true]] as [$water, $consistent]) {
            foreach ([-12.5, -1.2, 0.0, 3.4] as $change) {
                $facts = ['change_kg' => $change, 'days' => 28, 'likely_water_fluctuation' => $water, 'consistent_with_plan' => $consistent];
                $text = $service->fallbackText($facts);

                $this->assertSame($text, $service->accepted($text, $facts), "the fallback for {$change} is acceptable as it stands");
                $this->assertStringEndsWith(config('weight_insight.closing_ar'), $text);
            }
        }
    }

    // ---- caching ----------------------------------------------------------------------------------------------------------------

    public function test_it_is_generated_once_per_reading_pair(): void
    {
        $this->fourteenDays();
        Http::fake(['*' => Http::response($this->reply('نزل وزنك شوي. وأخصائيتك بتتابع معك.'))]);

        $this->getJson($this->url(), $this->nutritionistToken)->assertOk();
        $this->getJson($this->url(), $this->nutritionistToken)->assertOk();
        $this->getJson($this->url(), $this->nutritionistToken)->assertOk();

        Http::assertSentCount(1);
        $this->assertSame(1, WeightInsight::count());
        $row = WeightInsight::first();
        $this->assertSame([$this->day(14), $this->day(0)], [$row->from_date->toDateString(), $row->to_date->toDateString()]);
        $this->assertFalse($row->is_fallback);
        $this->assertEquals(-1.2, $row->facts['change_kg']);
    }

    public function test_a_new_reading_makes_a_new_insight(): void
    {
        $this->reading(21, 82.0);
        $this->reading(7, 80.0);
        Http::fake(['*' => Http::sequence()
            ->push($this->reply('الأول. وأخصائيتك بتتابع معك.'))
            ->push($this->reply('الثاني. وأخصائيتك بتتابع معك.'))]);

        $this->getJson($this->url(), $this->nutritionistToken)->assertOk()->assertJsonPath('insight.text_ar', 'الأول. وأخصائيتك بتتابع معك.');

        $this->reading(0, 79.0);

        $this->getJson($this->url(), $this->nutritionistToken)->assertOk()
            ->assertJsonPath('insight.text_ar', 'الثاني. وأخصائيتك بتتابع معك.')->assertJsonPath('insight.from_date', $this->day(7))->assertJsonPath('insight.to_date', $this->day(0));

        Http::assertSentCount(2);
        $this->assertSame(2, WeightInsight::count(), 'one per pair');
    }

    public function test_correcting_the_latest_reading_regenerates_for_the_same_end_date(): void
    {
        $this->fourteenDays();
        Http::fake(['*' => Http::sequence()
            ->push($this->reply('قبل. وأخصائيتك بتتابع معك.'))
            ->push($this->reply('بعد. وأخصائيتك بتتابع معك.'))]);

        $this->getJson($this->url(), $this->nutritionistToken)->assertJsonPath('insight.change_kg', -1.2);

        BodyCompositionReading::whereDate('recorded_at', $this->day(0))->update(['weight_kg' => 77.8]);

        $this->getJson($this->url(), $this->nutritionistToken)->assertJsonPath('insight.change_kg', -2.2)->assertJsonPath('insight.text_ar', 'بعد. وأخصائيتك بتتابع معك.');
        $this->assertSame(1, WeightInsight::count(), 'the unique (patient, end date) row is replaced');
    }

    public function test_regenerate_asks_for_a_fresh_wording_of_the_same_pair(): void
    {
        $this->fourteenDays();
        Http::fake(['*' => Http::sequence()
            ->push($this->reply('الأولى. وأخصائيتك بتتابع معك.'))
            ->push($this->reply('الثانية. وأخصائيتك بتتابع معك.'))]);

        $this->getJson($this->url(), $this->nutritionistToken)->assertJsonPath('insight.text_ar', 'الأولى. وأخصائيتك بتتابع معك.');
        $this->postJson($this->url('/regenerate'), [], $this->nutritionistToken)->assertOk()->assertJsonPath('insight.text_ar', 'الثانية. وأخصائيتك بتتابع معك.');

        Http::assertSentCount(2);
        $this->assertSame(1, WeightInsight::count());
    }

    public function test_regenerate_is_throttled(): void
    {
        $this->fourteenDays();
        Http::fake(['*' => Http::response($this->reply('نص. وأخصائيتك بتتابع معك.'))]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson($this->url('/regenerate'), [], $this->nutritionistToken)->assertOk();
        }

        $this->postJson($this->url('/regenerate'), [], $this->nutritionistToken)->assertStatus(429);
        Http::assertSentCount(5);
    }

    public function test_regenerate_without_readings_is_an_empty_answer_not_an_error(): void
    {
        $this->postJson($this->url('/regenerate'), [], $this->nutritionistToken)->assertOk()->assertJsonPath('insight', null);
    }

    // ---- visibility / API shape ---------------------------------------------------------------------------------------------------

    public function test_the_patient_sees_nothing_by_default(): void
    {
        $this->fourteenDays();
        Http::fake();

        $this->assertSame('null', $this->getJson('/api/v1/me/weight-insight', $this->clientToken)->assertOk()->getContent());
        Http::assertNothingSent();
        $this->assertSame(0, WeightInsight::count(), 'and nothing is generated for a patient who cannot see it');
        $this->assertFalse((bool) $this->subscriber->fresh()->show_weight_insight);
    }

    public function test_the_nutritionist_always_sees_it_with_its_facts(): void
    {
        $this->fourteenDays();
        Http::fake(['*' => Http::response($this->reply('نزل وزنك شوي. وأخصائيتك بتتابع معك.'))]);

        $response = $this->getJson($this->url(), $this->nutritionistToken)->assertOk();

        $response->assertJsonPath('visible_to_patient', false)
            ->assertJsonStructure(['visible_to_patient', 'insight' => ['from_date', 'to_date', 'change_kg', 'text_ar', 'is_fallback', 'generated_at', 'facts' => [
                'change_kg', 'days', 'adherence_percent', 'logged_days', 'off_plan_meals', 'high_sodium_logs', 'checkins', 'likely_water_fluctuation', 'consistent_with_plan',
            ]]]);
    }

    public function test_switching_it_on_gives_the_patient_exactly_the_change_and_the_text(): void
    {
        $this->fourteenDays();
        $this->log(5, 'off_plan', $this->salty());
        Http::fake(['*' => Http::response($this->reply('نزل وزنك شوي. وأخصائيتك بتتابع معك.'))]);

        $this->putJson($this->url('-visibility'), ['visible' => true], $this->nutritionistToken)->assertOk()->assertExactJson(['visible_to_patient' => true]);

        $response = $this->getJson('/api/v1/me/weight-insight', $this->clientToken)->assertOk();

        $this->assertSame(['from_date', 'to_date', 'change_kg', 'text_ar'], array_keys($response->json()), 'never any fact beyond the change');
        $response->assertExactJson([
            'from_date' => $this->day(14), 'to_date' => $this->day(0), 'change_kg' => -1.2, 'text_ar' => 'نزل وزنك شوي. وأخصائيتك بتتابع معك.',
        ]);
        foreach (['adherence', 'sodium', 'off_plan', 'logged', 'facts', 'is_fallback', 'checkin'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent());
        }

        $this->putJson($this->url('-visibility'), ['visible' => false], $this->nutritionistToken)->assertOk();
        $this->assertSame('null', $this->getJson('/api/v1/me/weight-insight', $this->clientToken)->getContent());
    }

    public function test_visible_but_not_enough_readings_is_null(): void
    {
        $this->subscriber->forceFill(['show_weight_insight' => true])->save();
        $this->reading(0, 80.0);

        $this->assertSame('null', $this->getJson('/api/v1/me/weight-insight', $this->clientToken)->assertOk()->getContent());
    }

    public function test_a_visible_insight_is_generated_on_the_patients_first_request_and_then_cached(): void
    {
        $this->fourteenDays();
        $this->subscriber->forceFill(['show_weight_insight' => true])->save();
        Http::fake(['*' => Http::response($this->reply('نزل وزنك شوي. وأخصائيتك بتتابع معك.'))]);

        $this->getJson('/api/v1/me/weight-insight', $this->clientToken)->assertOk()->assertJsonPath('change_kg', -1.2);
        $this->getJson('/api/v1/me/weight-insight', $this->clientToken)->assertOk();

        Http::assertSentCount(1);
    }

    public function test_the_visibility_flag_is_validated(): void
    {
        $this->putJson($this->url('-visibility'), [], $this->nutritionistToken)->assertStatus(422)->assertJsonValidationErrors('visible');
        $this->putJson($this->url('-visibility'), ['visible' => 'maybe'], $this->nutritionistToken)->assertStatus(422);
    }

    public function test_the_change_is_signed_and_one_decimal(): void
    {
        $this->reading(10, 80.04);
        $this->reading(0, 81.26);
        Http::fake(['*' => Http::response($this->reply('زاد وزنك شوي. وأخصائيتك بتتابع معك.'))]);
        $this->subscriber->forceFill(['show_weight_insight' => true])->save();

        $this->getJson('/api/v1/me/weight-insight', $this->clientToken)->assertOk()->assertJsonPath('change_kg', 1.2);
    }

    // ---- ownership ---------------------------------------------------------------------------------------------------------------------

    public function test_only_the_patients_own_nutritionist_can_read_or_change_it(): void
    {
        $this->fourteenDays();
        Http::fake(['*' => Http::response($this->reply('نص. وأخصائيتك بتتابع معك.'))]);

        $this->getJson($this->url(), $this->strangerToken)->assertNotFound();
        $this->putJson($this->url('-visibility'), ['visible' => true], $this->strangerToken)->assertNotFound();
        $this->postJson($this->url('/regenerate'), [], $this->strangerToken)->assertNotFound();
        $this->assertFalse((bool) $this->subscriber->fresh()->show_weight_insight);

        $this->getJson($this->url(), $this->clientToken)->assertForbidden();
        $this->putJson($this->url('-visibility'), ['visible' => true], $this->clientToken)->assertForbidden();
        $this->postJson($this->url('/regenerate'), [], $this->clientToken)->assertForbidden();

        $this->getJson('/api/v1/me/weight-insight', $this->nutritionistToken)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_one_patient_never_gets_anothers_insight(): void
    {
        $this->fourteenDays();
        $this->subscriber->forceFill(['show_weight_insight' => true])->save();
        Http::fake(['*' => Http::response($this->reply('نص. وأخصائيتك بتتابع معك.'))]);
        $this->getJson('/api/v1/me/weight-insight', $this->clientToken)->assertOk();

        $other = $this->otherSubscriber;
        $other->forceFill(['show_weight_insight' => true])->save();

        $this->assertSame('null', $this->getJson('/api/v1/me/weight-insight', $this->otherClientToken)->getContent());
        $this->assertSame(1, WeightInsight::where('subscriber_id', $this->subscriber->id)->count());
        $this->assertSame(0, WeightInsight::where('subscriber_id', $other->id)->count());
    }
}
