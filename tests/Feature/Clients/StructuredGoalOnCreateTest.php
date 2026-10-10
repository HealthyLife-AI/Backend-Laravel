<?php

namespace Tests\Feature\Clients;

use App\Models\PatientGoal;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * B10: patients are added with one of the 7 structured goals; the legacy
 * 4-value column follows PatientGoal::LEGACY_GOAL. B12: goal_type is the one
 * chosen (it used to be saved as 'other' for every patient).
 */
class StructuredGoalOnCreateTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->nutritionist = User::factory()->nutritionist()->create();
    }

    private function add(string $goal, int $n): array
    {
        return $this->postJson('/api/v1/clients', ['name' => "P{$n}", 'phone' => "+97059900100{$n}", 'username' => "goal.p{$n}", 'goal' => $goal], $this->bearerFor($this->nutritionist))
            ->assertCreated()->json('client');
    }

    public function test_every_type_is_saved_as_chosen_with_its_legacy_goal(): void
    {
        foreach (PatientGoal::TYPES as $n => $type) {
            $client = $this->add($type, $n);

            $this->assertSame($type, $client['goal_type']);
            $this->assertSame(PatientGoal::LEGACY_GOAL[$type], $client['goal']);
            $this->assertSame($type, PatientGoal::where('subscriber_id', $client['id'])->value('goal_type'));
            $this->assertSame(PatientGoal::LEGACY_GOAL[$type], Subscriber::find($client['id'])->goal);
        }
    }

    public function test_the_old_health_monitoring_value_is_read_as_health_energy(): void
    {
        $client = $this->add('health_monitoring', 1);

        $this->assertSame('health_energy', $client['goal_type']);
        $this->assertSame('health_monitoring', $client['goal']);
    }

    public function test_an_unknown_goal_is_refused(): void
    {
        $this->postJson('/api/v1/clients', ['name' => 'P', 'phone' => '+970599001009', 'username' => 'goal.bad', 'goal' => 'get_fit'], $this->bearerFor($this->nutritionist))
            ->assertUnprocessable()->assertJsonValidationErrors('goal');
    }

    public function test_the_roster_and_the_patient_carry_goal_type(): void
    {
        $client = $this->add('muscle_gain', 1);

        $this->getJson('/api/v1/clients', $this->bearerFor($this->nutritionist))->assertOk()->assertJsonPath('data.0.goal_type', 'muscle_gain');
        $this->getJson("/api/v1/clients/{$client['id']}", $this->bearerFor($this->nutritionist))->assertOk()->assertJsonPath('goal_type', 'muscle_gain')->assertJsonPath('goal', 'weight_gain');
    }
}
