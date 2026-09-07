<?php

namespace Tests\Unit\Models;

use App\Models\Concerns\BelongsToNutritionist;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * BR-2 / NFR-12: proves the global data-isolation scope works before any
 * real Sprint 2+ model exists to carry it, using a throwaway table.
 */
class NutritionistScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        Schema::create('scope_test_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nutritionist_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function test_a_nutritionist_only_sees_their_own_rows(): void
    {
        [$nutritionistA, $nutritionistB] = User::factory()->nutritionist()->count(2)->create();

        ScopeTestItem::query()->create(['nutritionist_id' => $nutritionistA->id, 'name' => 'A-item']);
        ScopeTestItem::query()->create(['nutritionist_id' => $nutritionistB->id, 'name' => 'B-item']);

        Auth::setUser($nutritionistA);

        $this->assertSame(['A-item'], ScopeTestItem::query()->pluck('name')->all());
    }

    public function test_creating_a_row_auto_fills_nutritionist_id_from_the_authenticated_nutritionist(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        Auth::setUser($nutritionist);

        $item = ScopeTestItem::query()->create(['name' => 'auto-filled']);

        $this->assertSame($nutritionist->id, $item->nutritionist_id);
    }

    public function test_a_client_sees_only_their_own_nutritionists_rows(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $otherNutritionist = User::factory()->nutritionist()->create();
        $client = User::factory()->client($nutritionist)->create();

        ScopeTestItem::query()->create(['nutritionist_id' => $nutritionist->id, 'name' => 'mine']);
        ScopeTestItem::query()->create(['nutritionist_id' => $otherNutritionist->id, 'name' => 'not-mine']);

        Auth::setUser($client);

        $this->assertSame(['mine'], ScopeTestItem::query()->pluck('name')->all());
    }

    public function test_an_admin_is_not_restricted_by_the_scope(): void
    {
        [$nutritionistA, $nutritionistB] = User::factory()->nutritionist()->count(2)->create();
        $admin = User::factory()->admin()->create();

        ScopeTestItem::query()->create(['nutritionist_id' => $nutritionistA->id, 'name' => 'A-item']);
        ScopeTestItem::query()->create(['nutritionist_id' => $nutritionistB->id, 'name' => 'B-item']);

        Auth::setUser($admin);

        $this->assertCount(2, ScopeTestItem::query()->get());
    }
}

/**
 * Throwaway model, local to this test, backed by the `scope_test_items`
 * table created in setUp(). Stands in for a Sprint 2+ model (health
 * profile, plan, log, ...) that will `use BelongsToNutritionist`.
 */
class ScopeTestItem extends Model
{
    use BelongsToNutritionist;

    protected $table = 'scope_test_items';

    protected $fillable = ['nutritionist_id', 'name'];
}
