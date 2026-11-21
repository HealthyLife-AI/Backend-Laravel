<?php

namespace Tests\Feature\Audit\Allergens;

use App\Models\Food;
use App\Models\HealthProfile;
use App\Models\MealPlan;
use App\Models\PatientAllergy;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * Audit I3: "no active plan contains a food the patient is allergic to without an explicit nutritionist
 * acknowledgement". Each test is one path by which an allergen reaches an ACTIVE plan unacknowledged.
 * These are expected to FAIL until fixed.
 */
class AllergenBypassTest extends TestCase
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

    private function createPlan(array $foods): int
    {
        return $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans", [
            'meals' => [['name' => 'snack', 'items' => array_map(fn (Food $f) => ['food_id' => $f->id, 'quantity_grams' => 50], $foods)]],
        ], $this->bearerFor($this->nutritionist))->assertCreated()->json('id');
    }

    private function activate(int $id): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$id}/activate", [], $this->bearerFor($this->nutritionist));
    }

    /** UsdaFoodSeeder.php:58 uses Food::insert(), which skips Food::booted()'s FoodTagger, so allergens stay NULL. */
    private function insertLikeUsdaSeeder(string $name, int $kcal): Food
    {
        Food::insert([[
            'source' => 'usda', 'status' => 'approved', 'name_en' => $name, 'name_ar' => null,
            'calories_per_100g' => $kcal, 'protein_g_per_100g' => 10, 'carbs_g_per_100g' => 50, 'fat_g_per_100g' => 25,
            'created_at' => now(), 'updated_at' => now(),
        ]]);

        return Food::query()->where('name_en', $name)->firstOrFail();
    }

    /** 7,793 of 7,851 seeded foods have allergens = NULL (USDA import). Group allergies only match tagged foods. */
    public function test_untagged_usda_peanut_food_activates_without_ack_for_a_confirmed_peanut_allergy(): void
    {
        $this->allergy('peanuts');
        $goobers = $this->insertLikeUsdaSeeder('Candies, NESTLE, GOOBERS Chocolate Covered Peanuts', 500);

        $res = $this->activate($this->createPlan([$goobers]));

        $this->assertSame(422, $res->status(), 'a confirmed peanut allergy + "Chocolate Covered Peanuts" activated with no acknowledgement: '.$res->status());
    }

    /** Arabic free-text allergy vs. a food whose name carries the definite article on the second word. */
    public function test_arabic_free_text_allergy_misses_food_with_definite_article(): void
    {
        $this->allergy('other', 'فول سوداني');
        $butter = Food::factory()->create(['name_en' => 'Nut spread', 'name_ar' => 'زبدة الفول السوداني', 'allergens' => [], 'status' => 'approved']);

        $this->assertSame(422, $this->activate($this->createPlan([$butter]))->status(), '"فول سوداني" did not match "زبدة الفول السوداني"');
    }

    /** English plural vs singular. */
    public function test_english_plural_free_text_allergy_misses_singular_food_name(): void
    {
        $this->allergy('other', 'peanuts');
        $food = Food::factory()->create(['name_en' => 'Peanut butter, smooth', 'name_ar' => null, 'allergens' => [], 'status' => 'approved']);

        $this->assertSame(422, $this->activate($this->createPlan([$food]))->status(), '"peanuts" did not match "Peanut butter"');
    }

    /** After activation, PUT replaces the meals of the ACTIVE plan with no allergen gate (the patient is notified of the new plan). */
    public function test_editing_an_active_plan_adds_a_tagged_allergen_without_ack(): void
    {
        $this->allergy('milk_lactose');
        $safe = Food::factory()->create(['allergens' => [], 'status' => 'approved']);
        $milk = Food::factory()->create(['name_en' => 'Milk, whole', 'name_ar' => 'حليب كامل الدسم', 'allergens' => ['milk_lactose'], 'status' => 'approved']);
        $id = $this->createPlan([$safe]);
        $this->activate($id)->assertOk();

        $res = $this->putJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$id}", [
            'meals' => [['name' => 'snack', 'items' => [['food_id' => $milk->id, 'quantity_grams' => 200]]]],
        ], $this->bearerFor($this->nutritionist));

        $plan = MealPlan::find($id);
        $hasMilk = $plan->meals()->with('items')->get()->flatMap->items->contains('food_id', $milk->id);
        $this->assertFalse($res->isSuccessful() && $plan->status === 'active' && $hasMilk && $plan->allergen_ack_at === null,
            "active plan now contains a confirmed-allergy food, never acknowledged (PUT -> {$res->status()})");
    }

    /** The rule-based fallback (used on every Groq failure / no key / monthly cap) draws from untagged foods too. */
    public function test_ai_fallback_draft_contains_untagged_peanut_food_for_peanut_allergy(): void
    {
        $this->allergy('peanuts');
        foreach (['Snacks, popcorn, caramel-coated, with peanuts', 'Candies, honey-combed, with peanut butter', 'Peanut butter, chunky'] as $name) {
            $this->insertLikeUsdaSeeder($name, 500);
        }
        Food::factory()->create(['name_en' => 'Rice, white, cooked', 'name_ar' => 'أرز', 'allergens' => [], 'status' => 'approved', 'calories_per_100g' => 130, 'meal_slots' => null]);

        $res = $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/ai-draft", [], $this->bearerFor($this->nutritionist));
        $res->assertSuccessful();
        $names = MealPlan::query()->where('is_ai_draft', true)->firstOrFail()->meals()->with('items.food')->get()->flatMap->items->map(fn ($i) => $i->food->name_en)->all();

        $this->assertEmpty(array_filter($names, fn ($n) => stripos($n, 'peanut') !== false), 'AI fallback draft for a peanut-allergic patient contains: '.implode(' | ', $names));
    }
}
