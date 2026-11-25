<?php

namespace Tests\Feature\MealPlans;

use App\Models\Food;
use App\Models\HealthProfile;
use App\Models\PatientAllergy;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * Audit A-03: activation, plan edits, the inline check and BOTH AI draft
 * paths use one matcher. The free-text cases the audit found missed:
 * «فول سوداني» vs «زبدة الفول السوداني», "peanuts" vs "Peanut butter",
 * and the Arabic article. Foods are stored with allergens [] where the test
 * is about the TEXT path, so a tag can't be what catches them.
 */
class SharedAllergenMatcherTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    private Subscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id]);
        HealthProfile::create([
            'subscriber_id' => $this->subscriber->id, 'weight_kg' => 70, 'height_cm' => 170, 'age' => 30,
            'gender' => 'female', 'activity_level' => 'sedentary', 'daily_calorie_needs' => 1800,
        ]);
    }

    private function allergy(string $group, ?string $text = null): void
    {
        PatientAllergy::create(['subscriber_id' => $this->subscriber->id, 'group' => $group, 'other_text' => $text, 'class' => 'confirmed_allergy']);
    }

    /** @param  list<string>|null  $allergens */
    private function food(string $nameEn, ?string $nameAr = null, ?array $allergens = []): Food
    {
        $food = Food::factory()->create(['name_en' => $nameEn, 'name_ar' => $nameAr, 'calories_per_100g' => 300]);
        $food->forceFill(['allergens' => $allergens])->saveQuietly();

        return $food->refresh();
    }

    private function createPlan(Food ...$foods): int
    {
        return $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans", [
            'meals' => [['name' => 'snack', 'items' => array_map(fn (Food $f) => ['food_id' => $f->id, 'quantity_grams' => 50], $foods)]],
        ], $this->bearerFor($this->nutritionist))->assertCreated()->json('id');
    }

    private function activate(int $planId): TestResponse
    {
        return $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$planId}/activate", [], $this->bearerFor($this->nutritionist));
    }

    public function test_arabic_free_text_allergy_matches_the_food_with_the_article(): void
    {
        $this->allergy('other', 'فول سوداني');
        $food = $this->food('Peanut spread', 'زبدة الفول السوداني');

        $this->activate($this->createPlan($food))->assertStatus(422)->assertJsonPath('code', 'allergen_conflict_unacknowledged');
    }

    public function test_english_plural_free_text_allergy_matches_the_singular_food_name(): void
    {
        $this->allergy('other', 'peanuts');
        $food = $this->food('Peanut butter, smooth');

        $this->activate($this->createPlan($food))->assertStatus(422);
    }

    public function test_an_allergy_typed_with_the_article_matches_a_name_without_it(): void
    {
        $this->allergy('other', 'السمسم');
        $food = $this->food('Sesame ring', 'كعك بسمسم');

        $this->activate($this->createPlan($food))->assertStatus(422);
    }

    public function test_a_group_allergy_matches_an_untagged_food_by_its_name(): void
    {
        $this->allergy('peanuts');
        $food = $this->food('Candies, NESTLE, GOOBERS Chocolate Covered Peanuts', null, null);

        $this->activate($this->createPlan($food))->assertStatus(422);
    }

    public function test_a_free_text_allergy_naming_a_group_matches_a_tagged_food(): void
    {
        $this->allergy('other', 'حليب');
        $food = $this->food('Cheese, cheddar', null, ['milk_lactose']);

        $this->activate($this->createPlan($food))->assertStatus(422);
    }

    public function test_a_safe_food_still_activates_without_acknowledgement(): void
    {
        $this->allergy('other', 'فول سوداني');
        $food = $this->food('Lentil soup', 'شوربة عدس');

        $this->activate($this->createPlan($food))->assertOk();
    }

    public function test_an_edit_to_an_active_plan_reports_the_text_match(): void
    {
        $this->allergy('other', 'فول سوداني');
        $safe = $this->food('Lentil soup', 'شوربة عدس');
        $peanut = $this->food('Peanut spread', 'زبدة الفول السوداني');
        $planId = $this->createPlan($safe);
        $this->activate($planId)->assertOk();

        $res = $this->putJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$planId}", [
            'meals' => [['name' => 'snack', 'items' => [['food_id' => $safe->id, 'quantity_grams' => 50], ['food_id' => $peanut->id, 'quantity_grams' => 30]]]],
        ], $this->bearerFor($this->nutritionist));

        $this->assertTrue((bool) $res->json('new_unacknowledged_conflicts'), 'the edit brought in a confirmed allergen and the report did not flag it');
    }

    public function test_the_inline_check_reports_the_text_match(): void
    {
        $this->allergy('other', 'peanuts');
        $food = $this->food('Peanut butter, smooth');

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/allergen-check", ['food_ids' => [$food->id]], $this->bearerFor($this->nutritionist))
            ->assertOk()
            ->assertJsonPath('foods.0.conflict.severity', 'confirmed_allergy');
    }

    public function test_the_rule_based_ai_draft_never_plans_the_allergen(): void
    {
        // Groq is not configured in tests, so the draft is rule-based. With the
        // allergenic food as the only approved food, a correct filter leaves
        // nothing to plan.
        Food::query()->delete();
        $this->allergy('other', 'فول سوداني');
        $this->food('Peanut spread', 'زبدة الفول السوداني');

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/ai-draft", [], $this->bearerFor($this->nutritionist))
            ->assertStatus(422);
    }

    public function test_the_llm_ai_draft_is_never_shown_the_allergen(): void
    {
        config(['ai.base_url' => 'https://fake-llm.test', 'ai.api_key' => 'k', 'ai.model' => 'openai/gpt-oss-120b']);
        $this->allergy('other', 'peanuts');
        $peanut = $this->food('Peanut butter, smooth');
        $this->food('Lentil soup', 'شوربة عدس');

        $prompts = [];
        Http::fake(function (Request $request) use (&$prompts) {
            $prompts[] = collect($request->data()['messages'] ?? [])->pluck('content')->implode("\n");

            return Http::response(['choices' => [['message' => ['content' => '{"meals":"nonsense"}']]]]);
        });

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/ai-draft", [], $this->bearerFor($this->nutritionist))->assertSuccessful();

        $this->assertNotEmpty($prompts, 'the model was never called');
        foreach ($prompts as $prompt) {
            $this->assertStringNotContainsString('Peanut butter, smooth', $prompt);
            $this->assertDoesNotMatchRegularExpression('/"id":\s*'.$peanut->id.'\b/', $prompt);
        }
    }
}
