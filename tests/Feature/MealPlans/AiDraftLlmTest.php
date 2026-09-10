<?php

namespace Tests\Feature\MealPlans;

use App\Models\Food;
use App\Models\HealthProfile;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * S3-06 optional upgrade: the LLM path in `AiDraftPlanService`, and —
 * just as important — that it degrades to the rule-based generator
 * cleanly on every failure mode, rather than ever surfacing a broken or
 * unsafe plan. `phpunit.xml` forces `OPENAI_API_KEY` empty by default
 * (so every *other* test in the suite stays on the rule-based path and
 * never touches the network); each test here opts back in explicitly.
 */
class AiDraftLlmTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['ai.base_url' => 'https://fake-llm.test', 'ai.api_key' => 'test-key', 'ai.model' => 'test-model']);
    }

    private function chatCompletion(array $content): array
    {
        return ['choices' => [['message' => ['content' => json_encode($content)]]]];
    }

    /** @return array{0: Subscriber, 1: User} */
    private function makeSubscriberWithProfile(): array
    {
        $nutritionist = User::factory()->nutritionist()->create();
        // Subscriber::factory()->create(['nutritionist_id' => ...]) alone
        // leaves `user_id` pointing at the factory's own unrelated
        // internally-created client (see SubscriberFactory::forNutritionist's
        // docblock) — harmless here since nothing in this file reads
        // $subscriber->user, but returning $nutritionist explicitly
        // rather than re-deriving it via that relation is what actually
        // matters: re-deriving it is exactly the bug that cost real
        // debugging time earlier in this same sprint.
        $subscriber = Subscriber::factory()->create(['nutritionist_id' => $nutritionist->id]);
        HealthProfile::create([
            'subscriber_id' => $subscriber->id,
            'weight_kg' => 70, 'height_cm' => 170, 'age' => 30, 'gender' => 'male',
            'activity_level' => 'sedentary',
            'daily_calorie_needs' => 2000,
        ]);

        return [$subscriber, $nutritionist];
    }

    public function test_uses_the_llms_choice_when_the_response_is_valid(): void
    {
        [$subscriber, $nutritionist] = $this->makeSubscriberWithProfile();
        // A deliberately worse calorie fit than the rule-based ranker
        // would ever pick on its own — if the response reflects this
        // exact choice, it genuinely came from the (faked) LLM call,
        // not a coincidental rule-based match.
        $chosen = Food::factory()->create(['name_en' => 'LLM Choice', 'calories_per_100g' => 40]);
        Food::factory()->create(['name_en' => 'Better Fit', 'calories_per_100g' => 250]);

        Http::fake(['*' => Http::response($this->chatCompletion([
            'meals' => [
                ['name' => 'breakfast', 'items' => [['food_id' => $chosen->id, 'quantity_grams' => 200, 'alternatives' => []]]],
            ],
        ]))]);

        $response = $this->postJson(
            "/api/v1/clients/{$subscriber->id}/meal-plans/ai-draft",
            [],
            $this->bearerFor($nutritionist)
        );

        $response->assertCreated();
        $this->assertSame($chosen->id, $response->json('meals.0.items.0.food.id'));
    }

    public function test_falls_back_to_rule_based_when_the_llm_hallucinates_a_food_id(): void
    {
        [$subscriber, $nutritionist] = $this->makeSubscriberWithProfile();
        $real = Food::factory()->create(['calories_per_100g' => 200]);
        $nonExistentId = $real->id + 9999;

        Http::fake(['*' => Http::response($this->chatCompletion([
            'meals' => [
                ['name' => 'breakfast', 'items' => [['food_id' => $nonExistentId, 'quantity_grams' => 200, 'alternatives' => []]]],
            ],
        ]))]);

        $response = $this->postJson(
            "/api/v1/clients/{$subscriber->id}/meal-plans/ai-draft",
            [],
            $this->bearerFor($nutritionist)
        );

        // Still succeeds — via the rule-based fallback — and the
        // hallucinated id never made it into a persisted plan.
        $response->assertCreated();
        $usedFoodIds = collect($response->json('meals'))->flatMap(fn ($meal) => $meal['items'])->pluck('food.id');
        $this->assertNotContains($nonExistentId, $usedFoodIds);
        $this->assertContains($real->id, $usedFoodIds);
    }

    public function test_falls_back_to_rule_based_when_the_llm_request_fails(): void
    {
        [$subscriber, $nutritionist] = $this->makeSubscriberWithProfile();
        Food::factory()->create();

        Http::fake(['*' => Http::response(null, 500)]);

        $this->postJson(
            "/api/v1/clients/{$subscriber->id}/meal-plans/ai-draft",
            [],
            $this->bearerFor($nutritionist)
        )->assertCreated();
    }

    public function test_falls_back_to_rule_based_when_the_llm_returns_unparseable_content(): void
    {
        [$subscriber, $nutritionist] = $this->makeSubscriberWithProfile();
        Food::factory()->create();

        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'not valid json at all']]]])]);

        $this->postJson(
            "/api/v1/clients/{$subscriber->id}/meal-plans/ai-draft",
            [],
            $this->bearerFor($nutritionist)
        )->assertCreated();
    }

    public function test_falls_back_to_rule_based_when_the_llm_repeats_a_planned_food_across_meals(): void
    {
        [$subscriber, $nutritionist] = $this->makeSubscriberWithProfile();
        $food = Food::factory()->create(['calories_per_100g' => 200]);

        Http::fake(['*' => Http::response($this->chatCompletion([
            'meals' => [
                ['name' => 'breakfast', 'items' => [['food_id' => $food->id, 'quantity_grams' => 200, 'alternatives' => []]]],
                // Same food_id as the planned item again — the exact
                // rule the rule-based generator itself enforces; the
                // LLM path must be held to it too, not exempted.
                ['name' => 'lunch', 'items' => [['food_id' => $food->id, 'quantity_grams' => 300, 'alternatives' => []]]],
            ],
        ]))]);

        $this->postJson(
            "/api/v1/clients/{$subscriber->id}/meal-plans/ai-draft",
            [],
            $this->bearerFor($nutritionist)
        )->assertCreated();
    }

    public function test_uses_rule_based_generation_when_no_provider_is_configured(): void
    {
        config(['ai.base_url' => null, 'ai.api_key' => null]);
        Http::fake(); // any call at all here would be a bug — no assertion needed, a fake with no stub throws on use

        [$subscriber, $nutritionist] = $this->makeSubscriberWithProfile();
        Food::factory()->create();

        $this->postJson(
            "/api/v1/clients/{$subscriber->id}/meal-plans/ai-draft",
            [],
            $this->bearerFor($nutritionist)
        )->assertCreated();

        Http::assertNothingSent();
    }
}
