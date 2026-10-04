<?php

namespace Tests\Feature\MealPlans;

use App\Models\Food;
use App\Models\HealthProfile;
use App\Models\MealPlan;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * An AI draft records which path produced it (is_ai_fallback), the
 * nutritionist sees it, the patient never does, and the LLM prompt
 * carries the allergy rule as a hard instruction.
 */
class AiDraftHonestyTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    private Subscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['ai.base_url' => 'https://fake-llm.test', 'ai.api_key' => 'test-key', 'ai.model' => 'test-model']);

        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->subscriber = Subscriber::factory()->active()->forNutritionist($this->nutritionist)->create();
        HealthProfile::create([
            'subscriber_id' => $this->subscriber->id,
            'weight_kg' => 70, 'height_cm' => 170, 'age' => 30, 'gender' => 'female',
            'activity_level' => 'sedentary',
            'allergies' => ['sesame', 'اللاكتوز'],
            'daily_calorie_needs' => 2000,
        ]);
    }

    private function draft(): TestResponse
    {
        return $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/ai-draft", [], $this->bearerFor($this->nutritionist))
            ->assertCreated();
    }

    private function validLlmResponse(Food $food): array
    {
        return ['choices' => [['message' => ['content' => json_encode([
            'meals' => [['name' => 'breakfast', 'items' => [['food_id' => $food->id, 'quantity_grams' => 200, 'alternatives' => []]]]],
        ])]]]];
    }

    public function test_an_llm_draft_is_not_a_fallback(): void
    {
        $food = Food::factory()->create(['name_en' => 'Foul', 'calories_per_100g' => 110]);
        Http::fake(['*' => Http::response($this->validLlmResponse($food))]);

        $response = $this->draft();

        $response->assertJsonPath('is_ai_draft', true)->assertJsonPath('is_ai_fallback', false);
    }

    public function test_an_http_500_from_the_provider_makes_a_fallback_draft(): void
    {
        Food::factory()->create();
        Http::fake(['*' => Http::response(null, 500)]);

        $this->draft()->assertJsonPath('is_ai_draft', true)->assertJsonPath('is_ai_fallback', true);
    }

    public function test_a_connection_error_makes_a_fallback_draft(): void
    {
        Food::factory()->create();
        Http::fake(fn () => throw new ConnectionException('Could not resolve host: fake-llm.test'));

        $this->draft()->assertJsonPath('is_ai_fallback', true);
    }

    public function test_an_invalid_response_makes_a_fallback_draft(): void
    {
        $real = Food::factory()->create();
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'meals' => [['name' => 'breakfast', 'items' => [['food_id' => $real->id + 999, 'quantity_grams' => 200]]]],
        ])]]]])]);

        $this->draft()->assertJsonPath('is_ai_fallback', true);
    }

    public function test_no_provider_configured_makes_a_fallback_draft(): void
    {
        config(['ai.base_url' => null, 'ai.api_key' => null]);
        Http::fake();
        Food::factory()->create();

        $this->draft()->assertJsonPath('is_ai_fallback', true);
        Http::assertNothingSent();
    }

    public function test_the_nutritionist_sees_the_flag_and_the_patient_never_does(): void
    {
        Food::factory()->create();
        Http::fake(['*' => Http::response(null, 500)]);
        $planId = $this->draft()->json('id');

        $this->getJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$planId}", $this->bearerFor($this->nutritionist))
            ->assertOk()->assertJsonPath('is_ai_fallback', true);

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$planId}/activate", [], $this->bearerFor($this->nutritionist))->assertOk();
        $this->assertTrue(MealPlan::find($planId)->is_ai_fallback, 'the record of which path made it survives activation');

        $this->getJson('/api/v1/me/meal-plan', $this->bearerFor($this->subscriber->user))
            ->assertOk()
            ->assertJsonMissingPath('is_ai_fallback')
            ->assertJsonPath('id', $planId);
    }

    public function test_the_prompt_carries_the_allergy_rule_and_the_patients_allergies(): void
    {
        $food = Food::factory()->create(['name_en' => 'Foul', 'calories_per_100g' => 110]);
        Http::fake(['*' => Http::response($this->validLlmResponse($food))]);

        $this->draft();

        Http::assertSent(function (Request $request) {
            $messages = $request->data()['messages'];
            $system = $messages[0]['content'];
            $user = json_decode($messages[1]['content'], true);

            return str_contains($system, 'NEVER choose a food (planned item OR alternative) that contains')
                && str_contains($system, 'must_avoid_allergies')
                && str_contains($system, 'sesame → tahini, hummus')
                && str_contains($system, 'milk / dairy / lactose → cheese')
                && str_contains($system, 'gluten / wheat → bread')
                && $user['must_avoid_allergies'] === ['sesame', 'اللاكتوز'];
        });
    }

    public function test_the_name_based_pre_filter_still_runs_before_the_prompt(): void
    {
        $safe = Food::factory()->create(['name_en' => 'Grilled Chicken', 'calories_per_100g' => 165]);
        $sesame = Food::factory()->create(['name_en' => 'Sesame Bar', 'calories_per_100g' => 500]);
        Http::fake(['*' => Http::response($this->validLlmResponse($safe))]);

        $this->draft();

        Http::assertSent(function (Request $request) use ($sesame) {
            $offered = collect(json_decode($request->data()['messages'][1]['content'], true)['available_foods'])->pluck(0);

            return ! $offered->contains($sesame->id);
        });
    }

    /**
     * Production regression: with the full catalog the prompt went past Groq's
     * per-request token limit (HTTP 413) and every draft fell back. The menu is
     * capped at 80 compact rows, curated Arabic dishes first.
     */
    public function test_a_large_catalog_still_makes_a_small_prompt_with_curated_dishes_first(): void
    {
        Food::factory()->count(1500)->create(['source' => 'usda']);
        $curated = Food::factory()->count(5)->sequence(fn ($s) => ['seed_key' => "dish-{$s->index}", 'source' => 'admin'])->create();
        $food = $curated->first();
        Http::fake(['*' => Http::response($this->validLlmResponse($food))]);

        $this->draft()->assertJsonPath('is_ai_fallback', false);

        Http::assertSent(function (Request $request) use ($curated) {
            $user = $request->data()['messages'][1]['content'];
            $payload = json_decode($user, true);
            $ids = array_column($payload['available_foods'], 0);

            return mb_strlen($user) < 8000
                && count($ids) === 80
                && array_slice($ids, 0, 5) === $curated->pluck('id')->all()
                && $payload['food_columns'][0] === 'id';
        });
    }

    public function test_the_providers_own_reason_is_kept_in_the_log(): void
    {
        Food::factory()->create();
        Http::fake(['*' => Http::response(['error' => ['message' => 'Request too large for model on tokens per minute (TPM): Limit 8000']], 413)]);
        Log::spy();

        $this->draft()->assertJsonPath('is_ai_fallback', true);

        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx = []) => str_contains($ctx['error'] ?? '', 'HTTP 413. Request too large'));
    }

    public function test_a_short_rate_limit_is_waited_out_once(): void
    {
        $food = Food::factory()->create(['name_en' => 'Foul', 'calories_per_100g' => 110]);
        Sleep::fake();
        Http::fakeSequence()->push(['error' => ['message' => 'Rate limit reached']], 429, ['Retry-After' => '3'])->push($this->validLlmResponse($food));

        $this->draft()->assertJsonPath('is_ai_fallback', false);
        Sleep::assertSequence([Sleep::for(3)->seconds()]);
    }

    public function test_a_long_rate_limit_falls_back_without_waiting(): void
    {
        Food::factory()->create();
        Sleep::fake();
        Http::fake(['*' => Http::response(['error' => ['message' => 'Rate limit reached']], 429, ['Retry-After' => '45'])]);

        $this->draft()->assertJsonPath('is_ai_fallback', true);
        Sleep::assertNeverSlept();
        Http::assertSentCount(1);
    }

    public function test_an_invalid_json_generation_is_retried_once(): void
    {
        $food = Food::factory()->create(['name_en' => 'Foul', 'calories_per_100g' => 110]);
        Http::fakeSequence()->push(['error' => ['message' => 'Failed to validate JSON']], 400)->push($this->validLlmResponse($food));

        $this->draft()->assertJsonPath('is_ai_fallback', false);
        Http::assertSentCount(2);
    }

    public function test_a_second_failure_falls_back(): void
    {
        Food::factory()->create();
        Http::fake(['*' => Http::response(['error' => ['message' => 'Failed to validate JSON']], 400)]);

        $this->draft()->assertJsonPath('is_ai_fallback', true);
        Http::assertSentCount(2);
    }
}
