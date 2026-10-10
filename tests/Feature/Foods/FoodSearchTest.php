<?php

namespace Tests\Feature\Foods;

use App\Models\Food;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/** FR-25: search in Arabic and English, approved foods only (BR-5). */
class FoodSearchTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_it_searches_by_english_prefix(): void
    {
        Food::factory()->create(['name_en' => 'Chicken Breast', 'status' => 'approved']);
        Food::factory()->create(['name_en' => 'Chickpeas', 'status' => 'approved']);
        Food::factory()->create(['name_en' => 'Rice', 'status' => 'approved']);

        $response = $this->getJson('/api/v1/foods/search?q=Chick', $this->bearerFor(User::factory()->nutritionist()->create()));

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_it_searches_by_arabic_prefix(): void
    {
        Food::factory()->create(['name_ar' => 'أرز أبيض', 'status' => 'approved']);
        Food::factory()->create(['name_ar' => 'أرز بسمتي', 'status' => 'approved']);
        Food::factory()->create(['name_ar' => 'دجاج مشوي', 'status' => 'approved']);

        $response = $this->getJson('/api/v1/foods/search?q='.urlencode('أرز'), $this->bearerFor(User::factory()->nutritionist()->create()));

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    /** B15: a whole-word match ranks before a longer word that merely starts with the term. */
    public function test_a_whole_word_ranks_before_a_word_that_starts_with_it(): void
    {
        Food::factory()->create(['name_en' => 'Salmonberries, raw (Alaska Native)', 'name_ar' => null, 'status' => 'approved']);
        Food::factory()->create(['name_en' => 'Salmon nuggets, breaded, frozen, heated', 'name_ar' => null, 'status' => 'approved']);
        Food::factory()->create(['name_en' => 'Fish, salmon, Atlantic, farmed, cooked, dry heat', 'name_ar' => null, 'status' => 'approved']);

        $names = collect($this->getJson('/api/v1/foods/search?q=salmon', $this->bearerFor(User::factory()->nutritionist()->create()))->assertOk()->json('data'))->pluck('name_en');

        $this->assertSame('Salmonberries, raw (Alaska Native)', $names->last());
        $this->assertCount(3, $names);
    }

    /** B15: the same Arabic word written with a final ة or ا is one word. */
    public function test_arabic_final_letter_variants_match_each_other(): void
    {
        Food::factory()->create(['name_en' => 'Fish, tuna, canned', 'name_ar' => 'سمك تونا معلب', 'status' => 'approved']);
        Food::factory()->create(['name_en' => 'Egg, boiled', 'name_ar' => 'بيضة مسلوقة', 'status' => 'approved']);
        $auth = $this->bearerFor(User::factory()->nutritionist()->create());

        $this->assertCount(1, $this->getJson('/api/v1/foods/search?q='.urlencode('تونة'), $auth)->assertOk()->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/foods/search?q='.urlencode('تونا'), $auth)->assertOk()->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/foods/search?q='.urlencode('بيضا'), $auth)->assertOk()->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/foods/search?q='.urlencode('بيضه'), $auth)->assertOk()->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/foods/search?q='.urlencode('تونس'), $auth)->assertOk()->json('data'));
    }

    public function test_pending_nutritionist_submissions_are_not_publicly_searchable(): void
    {
        Food::factory()->create(['name_en' => 'Grandmas Secret Stew', 'status' => 'pending', 'source' => 'nutritionist']);

        $response = $this->getJson('/api/v1/foods/search?q=Grandmas', $this->bearerFor(User::factory()->nutritionist()->create()));

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_a_query_shorter_than_two_characters_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/foods/search?q=a', $this->bearerFor(User::factory()->nutritionist()->create()));

        $response->assertUnprocessable();
    }
}
