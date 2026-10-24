<?php

namespace Tests\Feature\Phase2;

use App\Models\Food;
use App\Models\HealthProfile;
use App\Models\PatientFoodPreference;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * Phase 2, B: likes and dislikes. A soft preference, never an allergy; the
 * patient and the nutritionist each own their own rows.
 */
class FoodPreferencesTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    private User $client;

    private Subscriber $subscriber;

    private array $nutritionistToken;

    private array $clientToken;

    private array $otherClientToken;

    private array $strangerToken;

    private Subscriber $otherSubscriber;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->client = User::factory()->client($this->nutritionist)->create();
        $this->subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $this->client->id]);

        $otherClient = User::factory()->client($this->nutritionist)->create();
        $this->otherSubscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $otherClient->id]);

        $this->nutritionistToken = $this->bearerFor($this->nutritionist);
        $this->clientToken = $this->bearerFor($this->client);
        $this->otherClientToken = $this->bearerFor($otherClient);
        $this->strangerToken = $this->bearerFor(User::factory()->nutritionist()->create());
    }

    private function food(array $attrs = []): Food
    {
        return Food::factory()->create($attrs + ['allergens' => [], 'status' => 'approved']);
    }

    private function url(): string
    {
        return "/api/v1/clients/{$this->subscriber->id}/food-preferences";
    }

    private function patientPut(array $likes, array $dislikes, ?array $token = null)
    {
        return $this->putJson('/api/v1/me/food-preferences', ['likes' => $likes, 'dislikes' => $dislikes], $token ?? $this->clientToken);
    }

    private function nutritionistPut(array $likes, array $dislikes, ?array $token = null)
    {
        return $this->putJson($this->url(), ['likes' => $likes, 'dislikes' => $dislikes], $token ?? $this->nutritionistToken);
    }

    // ---- patient ---------------------------------------------------------------------

    public function test_the_patient_reads_an_empty_pair_then_sets_and_replaces_their_lists(): void
    {
        $this->getJson('/api/v1/me/food-preferences', $this->clientToken)->assertOk()->assertExactJson(['likes' => [], 'dislikes' => []]);

        [$a, $b, $c] = [$this->food(['name_ar' => 'تمر']), $this->food(['name_ar' => 'سمك']), $this->food(['name_ar' => 'كبدة'])];

        $response = $this->patientPut([$a->id], [$b->id, $c->id])->assertOk();

        $this->assertSame([['food_id' => $a->id, 'name_ar' => 'تمر', 'name_en' => $a->name_en, 'source' => 'patient']], $response->json('likes'));
        $this->assertSame([$b->id, $c->id], array_column($response->json('dislikes'), 'food_id'));
        $this->getJson('/api/v1/me/food-preferences', $this->clientToken)->assertOk()->assertJson($response->json());

        // Replace-all: the second PUT is the whole truth.
        $this->patientPut([$b->id], [])->assertOk();
        $this->assertSame([$b->id], array_column($this->getJson('/api/v1/me/food-preferences', $this->clientToken)->json('likes'), 'food_id'));
        $this->assertSame([], $this->getJson('/api/v1/me/food-preferences', $this->clientToken)->json('dislikes'));
        $this->assertSame(1, PatientFoodPreference::where('subscriber_id', $this->subscriber->id)->count());
    }

    public function test_a_food_without_an_arabic_name_falls_back_to_the_english_one(): void
    {
        $usda = $this->food(['name_ar' => null, 'name_en' => 'Chicken breast']);

        $this->patientPut([$usda->id], [])->assertOk()->assertJsonPath('likes.0.name_ar', 'Chicken breast');
    }

    public function test_a_food_cannot_be_in_both_lists_and_the_limits_and_foods_are_checked(): void
    {
        $a = $this->food();
        $pending = $this->food(['status' => 'pending']);

        $this->patientPut([$a->id], [$a->id])->assertStatus(422)->assertJsonValidationErrors('dislikes');
        $this->patientPut([$pending->id], [])->assertStatus(422)->assertJsonValidationErrors('likes.0');
        $this->patientPut([999999], [])->assertStatus(422)->assertJsonValidationErrors('likes.0');
        $this->patientPut([$a->id, $a->id], [])->assertStatus(422);
        $this->putJson('/api/v1/me/food-preferences', ['likes' => [$a->id]], $this->clientToken)->assertStatus(422)->assertJsonValidationErrors('dislikes');
        $this->putJson('/api/v1/me/food-preferences', ['likes' => 'x', 'dislikes' => []], $this->clientToken)->assertStatus(422);

        $thirtyOne = Food::factory()->count(31)->create(['allergens' => [], 'status' => 'approved'])->pluck('id')->all();
        $this->patientPut($thirtyOne, [])->assertStatus(422)->assertJsonValidationErrors('likes');
        $this->patientPut([], $thirtyOne)->assertStatus(422)->assertJsonValidationErrors('dislikes');
        $this->patientPut(array_slice($thirtyOne, 0, 30), array_slice($thirtyOne, 30))->assertOk();

        $this->assertSame(31, PatientFoodPreference::count(), 'only the valid one was stored');
    }

    public function test_the_patients_edit_never_touches_the_nutritionists_rows(): void
    {
        [$a, $b, $c] = [$this->food(), $this->food(), $this->food()];
        $this->nutritionistPut([$a->id], [$b->id])->assertOk();

        // Her rows survive an empty edit and an edit about other foods.
        $this->patientPut([], [])->assertOk();
        $this->patientPut([$c->id], [])->assertOk();

        $lists = $this->getJson('/api/v1/me/food-preferences', $this->clientToken)->json();
        $this->assertEqualsCanonicalizing([$a->id, $c->id], array_column($lists['likes'], 'food_id'));
        $this->assertSame([$b->id], array_column($lists['dislikes'], 'food_id'));
        $this->assertSame('nutritionist', collect($lists['likes'])->firstWhere('food_id', $a->id)['source']);
        $this->assertSame('patient', collect($lists['likes'])->firstWhere('food_id', $c->id)['source']);

        // Asking for what she already set is fine and changes nothing about who set it.
        $this->patientPut([$a->id, $c->id], [])->assertOk();
        $this->assertSame('nutritionist', PatientFoodPreference::where('food_id', $a->id)->sole()->source);

        // The opposite of what she set is refused, and nothing in that request is applied.
        $this->patientPut([$c->id], [$a->id])->assertStatus(422)->assertJsonValidationErrors('dislikes');
        $this->assertSame('like', PatientFoodPreference::where('food_id', $a->id)->sole()->kind);
    }

    public function test_the_patient_reads_only_their_own(): void
    {
        $a = $this->food();
        $this->patientPut([$a->id], [])->assertOk();

        $this->getJson('/api/v1/me/food-preferences', $this->otherClientToken)->assertOk()->assertExactJson(['likes' => [], 'dislikes' => []]);
        $this->patientPut([], [$a->id], $this->otherClientToken)->assertOk();

        $this->assertSame([$a->id], array_column($this->getJson('/api/v1/me/food-preferences', $this->clientToken)->json('likes'), 'food_id'));
        $this->assertSame(2, PatientFoodPreference::count());
    }

    public function test_a_nutritionist_token_cannot_use_the_patient_endpoints(): void
    {
        $this->getJson('/api/v1/me/food-preferences', $this->nutritionistToken)->assertForbidden();
        $this->patientPut([], [], $this->nutritionistToken)->assertForbidden();
        $this->getJson('/api/v1/me/food-preferences')->assertUnauthorized();
    }

    // ---- nutritionist ------------------------------------------------------------------------

    public function test_the_nutritionists_list_is_the_whole_list_and_kept_rows_keep_their_author(): void
    {
        [$a, $b, $c, $d] = [$this->food(), $this->food(), $this->food(), $this->food()];
        $this->patientPut([$a->id], [$b->id])->assertOk();

        // She keeps the patient's like (a), drops their dislike (b) by leaving it out, and adds two of her own.
        $this->nutritionistPut([$a->id, $c->id], [$d->id])->assertOk()
            ->assertJsonPath('likes.0.source', 'patient')->assertJsonPath('likes.1.source', 'nutritionist')->assertJsonPath('dislikes.0.source', 'nutritionist');

        $lists = $this->getJson($this->url(), $this->nutritionistToken)->assertOk()->json();
        $this->assertSame([$a->id, $c->id], array_column($lists['likes'], 'food_id'));
        $this->assertSame([$d->id], array_column($lists['dislikes'], 'food_id'), 'what she left out is gone, the patient row included');
        $this->assertSame('patient', PatientFoodPreference::where('food_id', $a->id)->sole()->source, 'unchanged, so still from the patient');

        // The patient can still manage their own row; her rows stay.
        $this->patientPut([], [])->assertOk();
        $this->assertEqualsCanonicalizing([$c->id, $d->id], PatientFoodPreference::pluck('food_id')->all());

        $this->nutritionistPut([], [])->assertOk();
        $this->assertSame(0, PatientFoodPreference::count());
    }

    public function test_what_the_nutritionist_lists_takes_over_a_patient_row_for_that_food(): void
    {
        [$a, $b] = [$this->food(), $this->food()];
        $this->patientPut([$a->id], [$b->id])->assertOk();

        $this->nutritionistPut([$b->id], [$a->id])->assertOk();

        $this->assertSame(['dislike', 'nutritionist'], [PatientFoodPreference::where('food_id', $a->id)->sole()->kind, PatientFoodPreference::where('food_id', $a->id)->sole()->source]);
        $this->assertSame(['like', 'nutritionist'], [PatientFoodPreference::where('food_id', $b->id)->sole()->kind, PatientFoodPreference::where('food_id', $b->id)->sole()->source]);

        // The patient's own edit now cannot undo it.
        $this->patientPut([$a->id], [])->assertStatus(422);
    }

    public function test_the_nutritionist_endpoints_validate_check_ownership_and_follow_up(): void
    {
        $a = $this->food();

        $this->nutritionistPut([$a->id], [$a->id])->assertStatus(422);
        $this->getJson($this->url(), $this->strangerToken)->assertNotFound();
        $this->nutritionistPut([$a->id], [], $this->strangerToken)->assertNotFound();
        $this->getJson($this->url(), $this->clientToken)->assertForbidden();
        $this->nutritionistPut([$a->id], [], $this->clientToken)->assertForbidden();

        $this->subscriber->forceFill(['archived_at' => now()])->save();
        $this->nutritionistPut([$a->id], [])->assertStatus(409)->assertJsonPath('code', 'follow_up_ended');
        $this->getJson($this->url(), $this->nutritionistToken)->assertOk();
        $this->assertSame(0, PatientFoodPreference::count());
    }

    // ---- planner data ----------------------------------------------------------------------------

    public function test_the_food_check_flags_each_food_with_the_preference(): void
    {
        [$liked, $disliked, $plain] = [$this->food(), $this->food(), $this->food()];
        $this->patientPut([$liked->id], [$disliked->id])->assertOk();

        $foods = collect($this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/allergen-check", ['food_ids' => [$plain->id, $disliked->id, $liked->id]], $this->nutritionistToken)
            ->assertOk()->json('foods'))->keyBy('food_id');

        $this->assertSame('like', $foods[$liked->id]['preference']);
        $this->assertSame('dislike', $foods[$disliked->id]['preference']);
        $this->assertNull($foods[$plain->id]['preference']);

        // With a quantity too.
        $withQty = $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/allergen-check", ['items' => [['food_id' => $liked->id, 'quantity_grams' => 100]]], $this->nutritionistToken)->json('foods.0.preference');
        $this->assertSame('like', $withQty);

        // Another patient's preferences are not this patient's.
        $other = $this->postJson("/api/v1/clients/{$this->otherSubscriber->id}/meal-plans/allergen-check", ['food_ids' => [$liked->id]], $this->nutritionistToken)->json('foods.0.preference');
        $this->assertNull($other);
    }

    public function test_the_plan_response_and_the_patients_plan_carry_no_preference_data(): void
    {
        $liked = $this->food();
        $this->patientPut([$liked->id], [])->assertOk();
        $plan = $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans", ['meals' => [['name' => 'lunch', 'items' => [['food_id' => $liked->id, 'quantity_grams' => 100]]]]], $this->nutritionistToken)->assertCreated();
        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$plan->json('id')}/activate", [], $this->nutritionistToken)->assertOk();

        $mine = $this->getJson('/api/v1/me/meal-plan', $this->clientToken)->assertOk()->getContent();

        $this->assertStringNotContainsString('preference', $mine);
        $this->assertStringNotContainsString('dislike', $mine);
    }

    // ---- AI draft ----------------------------------------------------------------------------------

    private function draftProfile(): void
    {
        HealthProfile::create([
            'subscriber_id' => $this->subscriber->id,
            'weight_kg' => 70, 'height_cm' => 170, 'age' => 30, 'gender' => 'male',
            'activity_level' => 'sedentary', 'daily_calorie_needs' => 2000,
        ]);
    }

    public function test_the_ai_prompt_carries_the_liked_and_disliked_names_and_nothing_else_new(): void
    {
        $this->draftProfile();
        config(['ai.base_url' => 'https://fake-llm.test', 'ai.api_key' => 'test-key', 'ai.model' => 'test-model']);
        $likedFood = $this->food(['name_ar' => 'تمر مجدول', 'name_en' => 'Date Medjool', 'calories_per_100g' => 280]);
        $dislikedFood = $this->food(['name_ar' => 'كبدة مشوية', 'name_en' => 'Liver grilled', 'calories_per_100g' => 190]);
        foreach (range(1, 5) as $i) {
            $this->food(['calories_per_100g' => 100 + $i * 20]);
        }
        $this->patientPut([$likedFood->id], [$dislikedFood->id])->assertOk();

        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '{"meals":[]}']]]], 200)]);

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/ai-draft", [], $this->nutritionistToken)->assertCreated();

        Http::assertSent(function (Request $request) use ($likedFood, $dislikedFood) {
            $prompt = $request->data()['messages'][1]['content'] ?? '';
            $payload = json_decode($prompt, true);

            return is_array($payload)
                && $payload['client_profile']['liked_foods'] === ['تمر مجدول']
                && $payload['client_profile']['disliked_foods'] === ['كبدة مشوية']
                // Names only: the preference rows' ids and sources are not in the prompt.
                && ! str_contains($prompt, 'source') && ! str_contains($prompt, '"nutritionist"')
                // A disliked food is not offered on the menu while others remain.
                && ! in_array($dislikedFood->id, array_column($payload['available_foods'], 0), true)
                && in_array($likedFood->id, array_column($payload['available_foods'], 0), true);
        });
    }

    public function test_the_prompt_says_the_preferences_are_soft(): void
    {
        $this->draftProfile();
        config(['ai.base_url' => 'https://fake-llm.test', 'ai.api_key' => 'test-key', 'ai.model' => 'test-model']);
        $this->food();
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '{"meals":[]}']]]], 200)]);

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/ai-draft", [], $this->nutritionistToken)->assertCreated();

        Http::assertSent(fn (Request $r) => str_contains($r->data()['messages'][0]['content'] ?? '', 'SOFT preferences'));
    }

    public function test_a_prompt_for_a_patient_with_no_preferences_has_empty_lists(): void
    {
        $this->draftProfile();
        config(['ai.base_url' => 'https://fake-llm.test', 'ai.api_key' => 'test-key', 'ai.model' => 'test-model']);
        $this->food();
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '{"meals":[]}']]]], 200)]);

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/ai-draft", [], $this->nutritionistToken)->assertCreated();

        Http::assertSent(function (Request $r) {
            $profile = json_decode($r->data()['messages'][1]['content'], true)['client_profile'] ?? [];

            return ! array_key_exists('liked_foods', $profile) && ! array_key_exists('disliked_foods', $profile);
        });
    }

    public function test_the_rule_based_draft_avoids_disliked_foods_when_others_remain_and_uses_them_when_none_do(): void
    {
        $this->draftProfile();
        $foods = collect(range(1, 6))->map(fn ($i) => $this->food(['calories_per_100g' => 80 + $i * 40]));
        $disliked = $foods->take(2);
        $this->patientPut([], $disliked->pluck('id')->all())->assertOk();

        $used = fn (array $plan) => collect($plan['meals'])->flatMap(fn ($m) => $m['items'])
            ->flatMap(fn ($i) => [$i['food']['id'], ...collect($i['alternatives'])->pluck('food.id')])->unique()->all();

        $draft = $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/ai-draft", [], $this->nutritionistToken)->assertCreated()->json();
        $this->assertSame([], array_intersect($used($draft), $disliked->pluck('id')->all()), 'a disliked food is not chosen while four others fit');

        // With too few other foods the preference gives way rather than leaving no plan.
        Food::query()->whereNotIn('id', $disliked->pluck('id')->push($foods[2]->id)->all())->update(['status' => 'pending']);
        $fallback = $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/ai-draft", [], $this->nutritionistToken)->assertCreated()->json();
        $this->assertNotSame([], $used($fallback));
    }
}
