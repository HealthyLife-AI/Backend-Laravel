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
        Http::fake(['*' => Http::response($this->reply('قليل الكربوهيدرات، عالي البروتين. راجعي الالتزام في الزيارة القادمة.'))]);

        $response = $this->suggest()->assertOk()->assertJsonPath('is_fallback', false)->assertJsonPath('suggested_approved_calories', 1650);

        // 500g at 200 kcal/100g = 1000 kcal; 50g protein, 100g carbs, 40g fat
        // → 200/400/360 kcal → 23% / 45% / 41% after rounding each share.
        $first = explode("\n", $response->json('suggested_summary'))[0];
        $this->assertStringStartsWith('1000 سعرة · بروتين ', $first);
        $this->assertStringContainsString('كربوهيدرات', $first);
        $this->assertStringContainsString('دهون', $first);
        $this->assertStringContainsString('عالي البروتين', $response->json('suggested_summary'));
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
