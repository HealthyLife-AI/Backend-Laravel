<?php

namespace Tests\Feature\MealPlans;

use App\Models\Food;
use App\Models\HealthProfile;
use App\Models\MealPlan;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * Item D: the suggested plan summary. A suggestion only — this endpoint
 * saves nothing — and every number in it is the server's, never the
 * model's.
 */
class PlanSummarySuggestionTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    private Subscriber $subscriber;

    /** @var array<string, mixed> The created plan as the API returned it. */
    private array $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['ai.base_url' => 'https://fake-llm.test', 'ai.api_key' => 'test-key', 'ai.model' => 'test-model']);

        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id]);

        HealthProfile::create([
            'subscriber_id' => $this->subscriber->id,
            'weight_kg' => 80, 'height_cm' => 170, 'age' => 30, 'gender' => 'female',
            'activity_level' => 'sedentary', 'daily_calorie_needs' => 1800,
            'nutritionist_notes' => 'ملاحظة خاصة جدًا عن الحالة النفسية',
            'lab_notes' => 'نتيجة تحليل حساسة',
        ]);

        $food = Food::factory()->create(['status' => 'approved', 'calories_per_100g' => 200, 'protein_g_per_100g' => 10, 'carbs_g_per_100g' => 20, 'fat_g_per_100g' => 8]);

        $this->plan = $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans", [
            'approved_calories' => 1650,
            'plan_summary' => 'ملخص قديم حساس',
            'meals' => [['name' => 'breakfast', 'items' => [['food_id' => $food->id, 'quantity_grams' => 500]]]],
        ], $this->token())->assertCreated()->json();
    }

    private function token(): array
    {
        return $this->bearerFor($this->nutritionist);
    }

    private function reply(string $summary): array
    {
        return ['choices' => [['message' => ['content' => json_encode(['summary' => $summary])]]]];
    }

    private function suggest(): TestResponse
    {
        return $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$this->plan['id']}/summary-suggestion", [], $this->token());
    }

    public function test_the_first_line_is_the_servers_own_figures(): void
    {
        Http::fake(['*' => Http::response($this->reply('نمط متوازن. راجعي الالتزام في الزيارة القادمة.'))]);

        $response = $this->suggest()->assertOk()->assertJsonPath('is_fallback', false)->assertJsonPath('suggested_approved_calories', 1650);

        // 500g at 200 kcal/100g = 1000 kcal; 50g protein, 100g carbs, 40g fat
        // → 200/400/360 kcal → 23% / 45% / 41% after rounding each share.
        $first = explode("\n", $response->json('suggested_summary'))[0];
        $this->assertStringStartsWith('1000 سعرة · بروتين ', $first);
        $this->assertStringContainsString('كربوهيدرات', $first);
        $this->assertStringContainsString('دهون', $first);
        $this->assertStringContainsString('نمط متوازن', $response->json('suggested_summary'));
    }

    /** The model may name a pattern; it may not produce a figure. */
    public function test_a_reply_that_invents_a_number_is_rejected(): void
    {
        Http::fake(['*' => Http::response($this->reply('خطة ممتازة عند 1450 سعرة تقريبًا.'))]);

        $response = $this->suggest()->assertOk()->assertJsonPath('is_fallback', true);

        $this->assertStringNotContainsString('1450', $response->json('suggested_summary'));
        $this->assertSame(1, substr_count($response->json('suggested_summary'), "\n") + 1);
    }

    public function test_an_over_long_reply_is_rejected(): void
    {
        Http::fake(['*' => Http::response($this->reply(str_repeat('ا', 251)))]);

        $this->suggest()->assertOk()->assertJsonPath('is_fallback', true);
    }

    public function test_the_first_line_alone_when_the_provider_fails(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);

        $this->suggest()->assertOk()->assertJsonPath('is_fallback', true)
            ->assertJsonPath('suggested_approved_calories', 1650);
    }

    private function jsonModeRefused(): array
    {
        return ['error' => ['message' => 'Failed to validate JSON.', 'type' => 'invalid_request_error', 'code' => 'json_validate_failed', 'failed_generation' => '{"summary": "cut']];
    }

    /** The provider refuses its JSON mode once: the client retries as plain text and the note is used. */
    public function test_the_note_survives_a_refused_json_mode(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->jsonModeRefused(), 400)
            ->push(['choices' => [['message' => ['content' => 'إليك الملخص: {"summary": "نمط متوازن. راجعي الالتزام في الزيارة القادمة."}']]]])]);

        $this->suggest()->assertOk()->assertJsonPath('is_fallback', false);
        Http::assertSentCount(2);
    }

    public function test_the_first_line_alone_when_json_mode_is_refused_twice(): void
    {
        Http::fake(['*' => Http::sequence()->push($this->jsonModeRefused(), 400)->push($this->jsonModeRefused(), 400)]);

        $this->suggest()->assertOk()->assertJsonPath('is_fallback', true)->assertJsonPath('suggested_approved_calories', 1650);
    }

    // ---- macro labels: the server decides, not the model -----------------------------------------------

    /** The fixture plan is 21% protein, 42% carbs, 38% fat: none of the three labels is earned. */
    private function sentFlags(): array
    {
        Http::fake(['*' => Http::response($this->reply('نمط متوازن. متابعة بعد أسبوعين.'))]);
        $this->suggest()->assertOk();

        $flags = [];
        Http::assertSent(function ($request) use (&$flags): bool {
            $user = (string) $request['messages'][1]['content'];
            foreach (['low_carb', 'high_protein', 'low_fat'] as $flag) {
                preg_match("/{$flag}: (true|false)/", $user, $m);
                $flags[$flag] = ($m[1] ?? null) === 'true';
            }
            $flags['prompt'] = $user;

            return true;
        });

        return $flags;
    }

    public function test_the_model_gets_the_flags_and_not_the_shares(): void
    {
        $flags = $this->sentFlags();

        $this->assertSame(['low_carb' => false, 'high_protein' => false, 'low_fat' => false], array_diff_key($flags, ['prompt' => 1]));
        $this->assertStringNotContainsString('share', $flags['prompt']);
        $this->assertStringNotContainsString('%', $flags['prompt']);
    }

    public function test_the_flags_follow_the_configured_thresholds_strictly(): void
    {
        // Shares of the fixture: protein 21, carbs 42, fat 38.
        config(['nutrition.macro_labels' => ['low_carb_max' => 42, 'high_protein_min' => 21, 'low_fat_max' => 38]]);
        $this->assertSame(['low_carb' => false, 'high_protein' => false, 'low_fat' => false], array_diff_key($this->sentFlags(), ['prompt' => 1]), 'on the line is not across it');

        config(['nutrition.macro_labels' => ['low_carb_max' => 43, 'high_protein_min' => 20, 'low_fat_max' => 39]]);
        $this->assertSame(['low_carb' => true, 'high_protein' => true, 'low_fat' => true], array_diff_key($this->sentFlags(), ['prompt' => 1]));
    }

    public function test_the_default_thresholds_are_the_clinics(): void
    {
        $this->assertSame(['low_carb_max' => 26, 'high_protein_min' => 30, 'low_fat_max' => 20], config('nutrition.macro_labels'));
    }

    /** @return array<string, array{string}> */
    public static function unearnedLabels(): array
    {
        return [
            'low carb' => ['خطة قليلة الكربوهيدرات. متابعة بعد أسبوعين.'],
            'low carb, other wording' => ['نمط منخفض الكربوهيدرات. متابعة بعد أسبوعين.'],
            'high protein' => ['نمط عالي البروتين. متابعة بعد أسبوعين.'],
            'rich in protein' => ['خطة غنية بالبروتين. متابعة بعد أسبوعين.'],
            'low fat' => ['نمط قليل الدهون. متابعة بعد أسبوعين.'],
            'adjective after the noun' => ['الكربوهيدرات القليلة في الخطة. متابعة بعد أسبوعين.'],
            'high carb' => ['نمط عالي الكربوهيدرات. متابعة بعد أسبوعين.'],
            'high fat' => ['نمط مرتفع الدهون. متابعة بعد أسبوعين.'],
            'low protein' => ['نمط قليل البروتين. متابعة بعد أسبوعين.'],
            'several' => ['قليل الكربوهيدرات، عالي البروتين. متابعة بعد أسبوعين.'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unearnedLabels')]
    public function test_a_macro_label_the_numbers_do_not_earn_falls_back_to_the_first_line(string $reply): void
    {
        Http::fake(['*' => Http::response($this->reply($reply))]);

        $response = $this->suggest()->assertOk()->assertJsonPath('is_fallback', true);

        $this->assertSame(1, substr_count($response->json('suggested_summary'), "\n") + 1, 'first line only');
    }

    private function planOf(float $protein, float $carbs, float $fat): void
    {
        $food = Food::factory()->create(['status' => 'approved', 'calories_per_100g' => 200, 'protein_g_per_100g' => $protein, 'carbs_g_per_100g' => $carbs, 'fat_g_per_100g' => $fat]);
        $this->plan = $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans", [
            'meals' => [['name' => 'breakfast', 'items' => [['food_id' => $food->id, 'quantity_grams' => 300]]]],
        ], $this->token())->assertCreated()->json();
    }

    public function test_a_plan_that_earns_the_labels_may_carry_them(): void
    {
        // 81% protein, 10% carbs, 9% fat.
        $this->planOf(40, 5, 2);
        Http::fake(['*' => Http::response($this->reply('قليل الكربوهيدرات، عالي البروتين، قليل الدهون. متابعة بعد أسبوعين.'))]);

        $this->suggest()->assertOk()->assertJsonPath('is_fallback', false);

        Http::assertSent(fn ($request) => str_contains((string) $request['messages'][1]['content'], 'low_carb: true')
            && str_contains((string) $request['messages'][1]['content'], 'high_protein: true')
            && str_contains((string) $request['messages'][1]['content'], 'low_fat: true'));
    }

    public function test_only_the_earned_label_is_allowed_when_just_one_is_true(): void
    {
        // About 31% protein, 50% carbs, 19% fat: high protein and low fat, but not low carb.
        $this->planOf(15.5, 25, 4.2);

        Http::fake(['*' => Http::sequence()
            ->push($this->reply('عالي البروتين وقليل الدهون. متابعة بعد أسبوعين.'))
            ->push($this->reply('قليل الكربوهيدرات، عالي البروتين. متابعة بعد أسبوعين.'))]);

        $this->suggest()->assertOk()->assertJsonPath('is_fallback', false);
        $this->suggest()->assertOk()->assertJsonPath('is_fallback', true);
    }

    public function test_a_note_that_says_nothing_about_macros_is_never_refused_for_it(): void
    {
        Http::fake(['*' => Http::response($this->reply('خطة متوازنة وسهلة التطبيق. راجعي الالتزام بعد أسبوعين.'))]);

        $this->suggest()->assertOk()->assertJsonPath('is_fallback', false);
    }

    // ---- why only the first line came back --------------------------------------------------------------

    public function test_a_note_from_the_model_has_no_fallback_reason(): void
    {
        Http::fake(['*' => Http::response($this->reply('نمط متوازن. متابعة بعد أسبوعين.'))]);

        $this->suggest()->assertOk()->assertJsonPath('is_fallback', false)->assertJsonPath('fallback_reason', null);
    }

    /** @return array<string, array{string, string}> reply => reason */
    public static function rejectionReasons(): array
    {
        return [
            'a macro label the numbers do not earn' => ['نمط قليل الكربوهيدرات. متابعة بعد أسبوعين.', 'macro_label'],
            'an invented number' => ['خطة ممتازة عند 1450 سعرة. متابعة بعد أسبوعين.', 'rejected_digit'],
            'nothing' => ['', 'empty'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectionReasons')]
    public function test_a_rejected_note_says_why(string $reply, string $reason): void
    {
        Http::fake(['*' => Http::response($this->reply($reply))]);

        $this->suggest()->assertOk()->assertJsonPath('is_fallback', true)->assertJsonPath('fallback_reason', $reason);
    }

    public function test_a_long_note_has_its_own_reason(): void
    {
        Http::fake(['*' => Http::response($this->reply(str_repeat('ا', 251)))]);

        $this->suggest()->assertOk()->assertJsonPath('fallback_reason', 'rejected_length');
    }

    public function test_provider_failure_and_no_provider_have_their_reasons(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);
        $this->suggest()->assertOk()->assertJsonPath('fallback_reason', 'provider_failed');

        config(['ai.base_url' => '', 'ai.api_key' => '']);
        $this->suggest()->assertOk()->assertJsonPath('fallback_reason', 'not_configured');
    }

    public function test_the_response_shape_is_the_four_fields(): void
    {
        Http::fake(['*' => Http::response($this->reply('نمط متوازن. متابعة بعد أسبوعين.'))]);

        $this->assertSame(['suggested_summary', 'suggested_approved_calories', 'is_fallback', 'fallback_reason'], array_keys($this->suggest()->json()));
    }

    public function test_the_first_line_alone_when_no_provider_is_configured(): void
    {
        config(['ai.base_url' => '', 'ai.api_key' => '']);
        Http::fake(); // any outbound call here would be a bug

        $this->suggest()->assertOk()->assertJsonPath('is_fallback', true);
    }

    /** No name, no phone, and none of the free text in the file. */
    public function test_nothing_sensitive_is_sent_to_the_model(): void
    {
        Http::fake(['*' => Http::response($this->reply('نمط متوازن. متابعة بعد أسبوعين.'))]);

        $this->suggest()->assertOk();

        Http::assertSent(function ($request) {
            $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            foreach ([$this->subscriber->user?->name, $this->subscriber->user?->phone, 'ملاحظة خاصة جدًا', 'نتيجة تحليل حساسة', 'ملخص قديم حساس'] as $secret) {
                if (filled($secret)) {
                    $this->assertStringNotContainsString((string) $secret, (string) $body);
                }
            }

            return true;
        });
    }

    public function test_it_saves_nothing(): void
    {
        Http::fake(['*' => Http::response($this->reply('نمط متوازن. متابعة بعد أسبوعين.'))]);

        $this->suggest()->assertOk();

        $this->assertSame('ملخص قديم حساس', MealPlan::find($this->plan['id'])->plan_summary);
    }

    public function test_another_nutritionist_cannot_ask_for_a_suggestion(): void
    {
        $stranger = User::factory()->nutritionist()->create();
        Http::fake();

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$this->plan['id']}/summary-suggestion", [], $this->bearerFor($stranger))
            ->assertNotFound();
    }
}
