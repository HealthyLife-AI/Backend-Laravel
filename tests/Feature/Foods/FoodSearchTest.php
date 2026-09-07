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
