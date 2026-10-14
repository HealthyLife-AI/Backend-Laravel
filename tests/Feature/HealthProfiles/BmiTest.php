<?php

namespace Tests\Feature\HealthProfiles;

use App\Models\BodyCompositionReading;
use App\Models\Subscriber;
use App\Models\User;
use App\Support\Bmi;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * Item B: BMI as the nutritionist entered it — her figure, not a derived
 * one, and never something the client can write (BR-11) or read.
 */
class BmiTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_the_nutritionist_stores_her_own_bmi_with_a_reading(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = Subscriber::factory()->create(['nutritionist_id' => $nutritionist->id]);

        $this->postJson("/api/v1/clients/{$subscriber->id}/body-composition-readings", [
            'recorded_at' => '2026-09-01', 'weight_kg' => 82, 'bmi' => 27.4,
        ], $this->bearerFor($nutritionist))->assertCreated()->assertJsonPath('bmi', 27.4);

        $this->assertSame('27.4', BodyCompositionReading::query()->latest('id')->first()->bmi);

        // Hers in the progress payload too.
        $this->getJson("/api/v1/clients/{$subscriber->id}/progress", $this->bearerFor($nutritionist))
            ->assertOk()->assertJsonPath('body_composition.latest.bmi', 27.4);
    }

    public function test_a_reading_without_a_bmi_keeps_it_null(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = Subscriber::factory()->create(['nutritionist_id' => $nutritionist->id]);

        $this->postJson("/api/v1/clients/{$subscriber->id}/body-composition-readings", [
            'recorded_at' => '2026-09-01', 'weight_kg' => 82,
        ], $this->bearerFor($nutritionist))->assertCreated()->assertJsonPath('bmi', null);
    }

    /** A slipped decimal point, not a person. */
    public function test_a_bmi_outside_10_to_80_is_rejected(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = Subscriber::factory()->create(['nutritionist_id' => $nutritionist->id]);
        $token = $this->bearerFor($nutritionist);

        foreach ([9.9, 80.1, 274] as $bmi) {
            $this->postJson("/api/v1/clients/{$subscriber->id}/body-composition-readings", [
                'recorded_at' => '2026-09-01', 'weight_kg' => 82, 'bmi' => $bmi,
            ], $token)->assertStatus(422)->assertJsonValidationErrors('bmi');
        }

        foreach ([10, 80] as $bmi) {
            $this->postJson("/api/v1/clients/{$subscriber->id}/body-composition-readings", [
                'recorded_at' => '2026-09-01', 'weight_kg' => 82, 'bmi' => $bmi,
            ], $token)->assertCreated();
        }
    }

    /** BR-11: bmi is not CLIENT_MEASURABLE, so a client sending one is ignored, not obeyed. */
    public function test_a_client_submitted_bmi_is_ignored(): void
    {
        $this->assertNotContains('bmi', BodyCompositionReading::CLIENT_MEASURABLE);

        $nutritionist = User::factory()->nutritionist()->create();
        $client = User::factory()->client($nutritionist)->create();
        Subscriber::factory()->create(['user_id' => $client->id, 'nutritionist_id' => $nutritionist->id]);

        $this->postJson('/api/v1/me/measurements', [
            'weight_kg' => 80, 'recorded_at' => now()->toDateString(), 'bmi' => 22.2,
        ], $this->bearerFor($client))->assertCreated();

        $this->assertNull(BodyCompositionReading::query()->latest('id')->first()->bmi);
    }

    /** The patient app is unchanged: no bmi in their own readings or progress. */
    public function test_the_patient_never_sees_bmi(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $client = User::factory()->client($nutritionist)->create();
        $subscriber = Subscriber::factory()->create(['user_id' => $client->id, 'nutritionist_id' => $nutritionist->id]);

        $this->postJson("/api/v1/clients/{$subscriber->id}/body-composition-readings", [
            'recorded_at' => now()->toDateString(), 'weight_kg' => 82, 'bmi' => 27.4,
        ], $this->bearerFor($nutritionist))->assertCreated();

        $own = $this->getJson('/api/v1/me/measurements', $this->bearerFor($client))->assertOk();
        $this->assertArrayNotHasKey('bmi', $own->json('0'));

        $progress = $this->getJson('/api/v1/me/progress', $this->bearerFor($client))->assertOk();
        $this->assertArrayNotHasKey('bmi', $progress->json('body_composition.latest'));
    }

    public function test_the_computed_value_and_its_category(): void
    {
        $this->assertSame(27.4, Bmi::compute(82, 173));
        $this->assertSame(22.0, Bmi::compute(64, 170.5));
        $this->assertNull(Bmi::compute(82, null));
        $this->assertNull(Bmi::compute(82, 0));

        $this->assertSame(Bmi::UNDERWEIGHT, Bmi::category(18.4, 30));
        $this->assertSame(Bmi::NORMAL, Bmi::category(18.5, 30));
        $this->assertSame(Bmi::NORMAL, Bmi::category(24.9, 30));
        $this->assertSame(Bmi::OVERWEIGHT, Bmi::category(25.0, 30));
        $this->assertSame(Bmi::OVERWEIGHT, Bmi::category(29.9, 30));
        $this->assertSame(Bmi::OBESITY, Bmi::category(30.0, 30));

        // Adults only: a child's BMI is read off percentile charts, so no label.
        $this->assertNull(Bmi::category(30.0, 17));
        $this->assertNull(Bmi::category(30.0, null));
        $this->assertSame(Bmi::OBESITY, Bmi::category(30.0, 18));
    }
}
