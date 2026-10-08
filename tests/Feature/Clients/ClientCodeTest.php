<?php

namespace Tests\Feature\Clients;

use App\Models\Subscriber;
use App\Models\User;
use App\Services\Clients\ClientCodeAllocator;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\Concerns\AuthenticatesForApi;
use Tests\Concerns\ExercisesMigrations;
use Tests\TestCase;

/**
 * Patient codes come from a per-nutritionist counter that never goes down
 * (ClientCodeAllocator), so a code is never reused — not even after the
 * highest-numbered patient is deleted. Before this, the code was
 * `count + 101`: any delete made the next patient collide with an existing
 * code and fail with a 500.
 */
class ClientCodeTest extends TestCase
{
    use AuthenticatesForApi, ExercisesMigrations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function addClient(User $nutritionist, string $phone): TestResponse
    {
        return $this->postJson('/api/v1/clients', ['name' => "Client {$phone}", 'phone' => $phone, 'username' => "client{$phone}.{$nutritionist->id}", 'goal' => 'weight_loss'], $this->bearerFor($nutritionist));
    }

    public function test_deleting_the_highest_numbered_patient_does_not_free_its_code(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $codes = [];
        foreach (['111', '222', '333'] as $phone) {
            $codes[] = $this->addClient($nutritionist, $phone)->assertCreated();
        }
        $this->assertSame('PT-103', $codes[2]->json('client.code'));

        $this->deleteJson('/api/v1/clients/'.$codes[2]->json('client.id'), [], $this->bearerFor($nutritionist))->assertNoContent();

        $next = $this->addClient($nutritionist, '444')->assertCreated();

        $this->assertSame('PT-104', $next->json('client.code'), 'the deleted PT-103 must never be handed out again');
    }

    public function test_deleting_a_lower_numbered_patient_no_longer_makes_the_next_one_collide(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $first = $this->addClient($nutritionist, '111')->assertCreated();
        $this->addClient($nutritionist, '222')->assertCreated();
        $this->addClient($nutritionist, '333')->assertCreated();

        // Old behaviour: count(2) + 101 = PT-103, which still exists → 500.
        $this->deleteJson('/api/v1/clients/'.$first->json('client.id'), [], $this->bearerFor($nutritionist))->assertNoContent();

        $next = $this->addClient($nutritionist, '444')->assertCreated();

        $this->assertSame('PT-104', $next->json('client.code'));
        $this->assertSame(3, Subscriber::withoutGlobalScopes()->where('nutritionist_id', $nutritionist->id)->count());
    }

    public function test_each_nutritionist_has_an_independent_sequence(): void
    {
        $a = User::factory()->nutritionist()->create();
        $b = User::factory()->nutritionist()->create();

        $this->assertSame('PT-101', $this->addClient($a, '111')->json('client.code'));
        $this->assertSame('PT-102', $this->addClient($a, '222')->json('client.code'));
        $this->assertSame('PT-101', $this->addClient($b, '111')->json('client.code'));
    }

    public function test_a_code_already_in_use_is_skipped(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        // Written outside the allocator (import, manual insert, factory).
        Subscriber::factory()->forNutritionist($nutritionist)->create(['code' => 'PT-101']);

        $this->assertSame('PT-102', $this->addClient($nutritionist, '111')->json('client.code'));
    }

    public function test_a_failed_creation_does_not_burn_a_number(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();

        // Missing goal → rejected by validation before anything is written.
        $this->postJson('/api/v1/clients', ['name' => 'X', 'phone' => '111'], $this->bearerFor($nutritionist))->assertUnprocessable();

        $this->assertSame('PT-101', $this->addClient($nutritionist, '222')->json('client.code'));
    }

    public function test_the_allocator_refuses_to_run_outside_a_transaction(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $levels = DB::transactionLevel();

        // RefreshDatabase wraps the test in a transaction; step out of it.
        for ($i = 0; $i < $levels; $i++) {
            DB::rollBack();
        }

        try {
            $this->expectException(LogicException::class);
            app(ClientCodeAllocator::class)->next($nutritionist);
        } finally {
            for ($i = 0; $i < $levels; $i++) {
                DB::beginTransaction();
            }
        }
    }

    public function test_the_migration_starts_each_counter_at_the_highest_code_in_use_and_rolls_back(): void
    {
        $migration = $this->migrationFile('2026_09_29_100000_add_client_code_counter_to_users_table');
        $withPatients = User::factory()->nutritionist()->create();
        $withoutPatients = User::factory()->nutritionist()->create();

        $migration->down();

        try {
            $this->assertFalse(Schema::hasColumn('users', 'client_code_counter'), 'rollback drops the column');

            // Legacy roster with a gap and a non-standard code.
            foreach (['PT-101', 'PT-107', 'PT-104', 'LEGACY-9'] as $code) {
                Subscriber::factory()->forNutritionist($withPatients)->create(['code' => $code]);
            }

            $migration->up();

            $counter = fn (User $u) => (int) DB::table('users')->where('id', $u->id)->value('client_code_counter');
            $this->assertSame(107, $counter($withPatients), 'starts at the highest PT-number, gaps and odd codes ignored');
            $this->assertSame(100, $counter($withoutPatients), 'a nutritionist with no patients starts at the default');
        } finally {
            if (! Schema::hasColumn('users', 'client_code_counter')) {
                $migration->up();
            }
        }

        // And the next patient follows the backfilled counter.
        $this->assertSame('PT-108', $this->addClient($withPatients, '999')->json('client.code'));
    }
}
