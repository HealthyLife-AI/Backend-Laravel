<?php

namespace Tests\Feature\Nutritionists;

use App\Models\HealthProfile;
use App\Models\NutritionistProfile;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\AuthenticatesForApi;
use Tests\Concerns\ExercisesMigrations;
use Tests\TestCase;

/**
 * What the patient app shows about "my nutritionist" (name, gender, clinic,
 * specialty, WhatsApp number), where the nutritionist sets it, and the
 * patient's own gender in their /auth/me.
 */
class PatientNutritionistCardTest extends TestCase
{
    use AuthenticatesForApi, ExercisesMigrations, RefreshDatabase;

    private User $nutritionist;

    private User $client;

    private Subscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->nutritionist = User::factory()->nutritionist()->create(['name' => 'Dr. Amal', 'email' => 'amal@example.com']);
        $this->client = User::factory()->create(['nutritionist_id' => $this->nutritionist->id]);
        $this->client->assignRole('client');
        $this->subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $this->client->id]);
    }

    private function saveProfile(array $extra = []): NutritionistProfile
    {
        return NutritionistProfile::create($extra + ['user_id' => $this->nutritionist->id]);
    }

    // ---- nutritionist edits their profile ---------------------------------

    public function test_a_nutritionist_sets_and_clears_gender_and_whatsapp(): void
    {
        $token = $this->bearerFor($this->nutritionist);

        $this->putJson('/api/v1/me/nutritionist-profile', ['gender' => 'female', 'whatsapp_number' => '+970599123456'], $token)
            ->assertOk()->assertJsonPath('gender', 'female')->assertJsonPath('whatsapp_number', '+970599123456');
        $this->getJson('/api/v1/me/nutritionist-profile', $token)->assertJsonPath('whatsapp_number', '+970599123456');

        $this->putJson('/api/v1/me/nutritionist-profile', ['gender' => null, 'whatsapp_number' => null], $token)
            ->assertOk()->assertJsonPath('gender', null)->assertJsonPath('whatsapp_number', null);
    }

    public function test_the_two_fields_are_optional_and_untouched_when_omitted(): void
    {
        $this->saveProfile(['gender' => 'male', 'whatsapp_number' => '+970599123456']);

        $this->putJson('/api/v1/me/nutritionist-profile', ['specialty' => 'Sports'], $this->bearerFor($this->nutritionist))
            ->assertOk()->assertJsonPath('gender', 'male')->assertJsonPath('whatsapp_number', '+970599123456');
    }

    public function test_whatsapp_must_be_e164_and_gender_male_or_female(): void
    {
        $token = $this->bearerFor($this->nutritionist);

        foreach (['0599123456', '970599123456', '+0599123456', '+97059912345678901', '+9705 99123456', '+97059', 'abc', '+970-599-123456'] as $bad) {
            $this->putJson('/api/v1/me/nutritionist-profile', ['whatsapp_number' => $bad], $token)
                ->assertUnprocessable()->assertJsonValidationErrors('whatsapp_number');
        }
        $this->putJson('/api/v1/me/nutritionist-profile', ['gender' => 'other'], $token)->assertUnprocessable()->assertJsonValidationErrors('gender');
        $this->assertDatabaseCount('nutritionist_profiles', 0);
    }

    // ---- GET /me/nutritionist ----------------------------------------------

    public function test_a_patient_reads_their_nutritionists_card(): void
    {
        $this->saveProfile(['gender' => 'female', 'clinic_name' => 'Gaza Nutrition Center', 'specialty' => 'Clinical nutrition', 'whatsapp_number' => '+970599123456', 'bio' => 'private bio']);

        $this->getJson('/api/v1/me/nutritionist', $this->bearerFor($this->client))
            ->assertOk()
            ->assertExactJson([
                'name' => 'Dr. Amal',
                'gender' => 'female',
                'clinic_name' => 'Gaza Nutrition Center',
                'specialty' => 'Clinical nutrition',
                'whatsapp_number' => '+970599123456',
            ]);
    }

    public function test_the_card_is_null_filled_when_the_nutritionist_has_not_set_a_profile(): void
    {
        $this->getJson('/api/v1/me/nutritionist', $this->bearerFor($this->client))
            ->assertOk()
            ->assertExactJson(['name' => 'Dr. Amal', 'gender' => null, 'clinic_name' => null, 'specialty' => null, 'whatsapp_number' => null]);

        // Reading it must not create a row on the nutritionist's behalf.
        $this->assertDatabaseCount('nutritionist_profiles', 0);
    }

    public function test_a_patient_only_ever_sees_their_own_nutritionist(): void
    {
        $other = User::factory()->nutritionist()->create(['name' => 'Dr. Other']);
        NutritionistProfile::create(['user_id' => $other->id, 'whatsapp_number' => '+970599000000']);
        $this->saveProfile(['whatsapp_number' => '+970599123456']);

        $this->getJson('/api/v1/me/nutritionist', $this->bearerFor($this->client))
            ->assertOk()->assertJsonPath('name', 'Dr. Amal')->assertJsonPath('whatsapp_number', '+970599123456');
    }

    public function test_a_nutritionist_cannot_use_the_patient_endpoint(): void
    {
        $this->getJson('/api/v1/me/nutritionist', $this->bearerFor($this->nutritionist))->assertForbidden();
        $this->getJson('/api/v1/me/nutritionist')->assertUnauthorized();
    }

    public function test_an_archived_patient_gets_follow_up_ended(): void
    {
        $token = $this->bearerFor($this->client);
        $this->subscriber->forceFill(['archived_at' => now()])->save();

        $this->getJson('/api/v1/me/nutritionist', $token)->assertForbidden()->assertJsonPath('code', 'follow_up_ended');
    }

    // ---- the patient's own gender in /auth/me ------------------------------

    public function test_the_patients_gender_comes_from_their_health_profile(): void
    {
        $token = $this->bearerFor($this->client);

        $this->getJson('/api/v1/auth/me', $token)->assertOk()->assertJsonPath('gender', null);

        HealthProfile::create(['subscriber_id' => $this->subscriber->id, 'weight_kg' => 70, 'height_cm' => 170, 'age' => 30, 'gender' => 'female', 'activity_level' => 'moderate']);

        $this->getJson('/api/v1/auth/me', $token)->assertOk()->assertJsonPath('gender', 'female');
    }

    public function test_a_nutritionists_me_has_no_gender_key(): void
    {
        $this->assertArrayNotHasKey('gender', $this->getJson('/api/v1/auth/me', $this->bearerFor($this->nutritionist))->assertOk()->json());
    }

    // ---- the migration -----------------------------------------------------

    public function test_the_migration_adds_nullable_columns_leaves_existing_rows_null_and_rolls_back(): void
    {
        $migration = $this->migrationFile('2026_09_29_120000_add_gender_and_whatsapp_to_nutritionist_profiles_table');

        $migration->down();
        try {
            $this->assertFalse(Schema::hasColumn('nutritionist_profiles', 'gender'));
            $this->assertFalse(Schema::hasColumn('nutritionist_profiles', 'whatsapp_number'));
            DB::table('nutritionist_profiles')->insert(['user_id' => $this->nutritionist->id, 'plan_tier' => 'basic', 'created_at' => now(), 'updated_at' => now()]);

            $migration->up();

            $row = DB::table('nutritionist_profiles')->first();
            $this->assertNull($row->gender);
            $this->assertNull($row->whatsapp_number);
        } finally {
            if (! Schema::hasColumn('nutritionist_profiles', 'gender')) {
                $migration->up();
            }
        }
    }
}
