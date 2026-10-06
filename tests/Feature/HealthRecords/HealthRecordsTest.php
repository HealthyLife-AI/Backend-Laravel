<?php

namespace Tests\Feature\HealthRecords;

use App\Models\Food;
use App\Models\HealthProfile;
use App\Models\NotificationPreference;
use App\Models\PatientAllergy;
use App\Models\PatientGoal;
use App\Models\PatientMedication;
use App\Models\PatientNotification;
use App\Models\ProfileProposal;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AuthenticatesForApi;
use Tests\Concerns\FakesPush;
use Tests\TestCase;

/** Step 1: the patient's goal, medications and allergies, and proposals the nutritionist decides. */
class HealthRecordsTest extends TestCase
{
    use AuthenticatesForApi, FakesPush, RefreshDatabase;

    private User $nutritionist;

    private Subscriber $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->fakePush();

        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->patient = Subscriber::factory()->active()->forNutritionist($this->nutritionist)->create(['goal' => 'weight_loss']);
        $this->patient->user->forceFill(['fcm_token' => 'device-1'])->save();
        NotificationPreference::for($this->patient->user)->update(['quiet_hours_enabled' => false]);
        HealthProfile::create([
            'subscriber_id' => $this->patient->id, 'weight_kg' => 80, 'height_cm' => 170, 'age' => 30, 'gender' => 'female',
            'activity_level' => 'light', 'daily_calorie_needs' => 1900,
        ]);
    }

    private function nurse(): array
    {
        return $this->bearerFor($this->nutritionist);
    }

    private function me(): array
    {
        return $this->bearerFor($this->patient->user);
    }

    private function propose(array $body): TestResponse
    {
        return $this->postJson('/api/v1/me/proposals', $body, $this->me());
    }

    private function approve(int $id, array $body = []): TestResponse
    {
        return $this->postJson("/api/v1/clients/{$this->patient->id}/proposals/{$id}/approve", $body, $this->nurse());
    }

    // ---- proposals --------------------------------------------------------

    public function test_nothing_a_patient_proposes_applies_until_approved(): void
    {
        $id = $this->propose(['kind' => 'allergy', 'action' => 'add', 'data' => ['group' => 'sesame', 'class' => 'confirmed_allergy']])
            ->assertCreated()->assertJsonPath('status', 'pending')->json('id');

        $this->assertSame(0, PatientAllergy::count());
        $this->getJson('/api/v1/me/health-profile', $this->me())->assertOk()->assertJsonCount(0, 'allergies')->assertJsonPath('proposals.0.id', $id);
        $this->getJson("/api/v1/clients/{$this->patient->id}/health-records", $this->nurse())->assertOk()->assertJsonPath('proposals.0.id', $id);

        $this->approve($id, ['note' => 'تم'])->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('decision_note', 'تم');

        $this->assertSame(['sesame'], PatientAllergy::pluck('group')->all());
        $this->assertContains('السمسم', $this->patient->healthProfile->fresh()->allergies, 'the JSON mirror follows');
    }

    public function test_edit_and_remove_of_a_medication_are_proposals_too(): void
    {
        $med = PatientMedication::create(['subscriber_id' => $this->patient->id, 'name' => 'Metformin', 'dose' => '500 mg', 'timing' => 'with']);

        $edit = $this->propose(['kind' => 'medication', 'action' => 'edit', 'target_id' => $med->id, 'data' => ['name' => 'Metformin', 'dose' => '850 mg', 'timing' => 'after']])->assertCreated()->json('id');
        $this->propose(['kind' => 'medication', 'action' => 'remove', 'target_id' => $med->id])->assertStatus(409)->assertJsonPath('code', 'proposal_exists');
        $this->assertSame('500 mg', $med->fresh()->dose);

        $this->approve($edit)->assertOk();
        $this->assertSame(['850 mg', 'after'], [$med->fresh()->dose, $med->fresh()->timing]);

        $remove = $this->propose(['kind' => 'medication', 'action' => 'remove', 'target_id' => $med->id])->assertCreated()->json('id');
        $this->approve($remove)->assertOk();
        $this->assertNotNull($med->fresh()->archived_at);
    }

    public function test_removing_a_confirmed_allergy_never_takes_effect_without_approval(): void
    {
        $allergy = PatientAllergy::create(['subscriber_id' => $this->patient->id, 'group' => 'peanuts', 'class' => 'confirmed_allergy']);

        $id = $this->propose(['kind' => 'allergy', 'action' => 'remove', 'target_id' => $allergy->id])->assertCreated()->json('id');
        $this->assertNotNull($allergy->fresh());

        $this->postJson("/api/v1/clients/{$this->patient->id}/proposals/{$id}/reject", ['note' => 'نراجعها بالجلسة'], $this->nurse())
            ->assertOk()->assertJsonPath('status', 'rejected');
        $this->assertNotNull($allergy->fresh());
        $this->approve($id)->assertStatus(409)->assertJsonPath('code', 'proposal_not_pending');
    }

    public function test_a_goal_proposal_with_any_type_updates_the_goal_and_the_legacy_column(): void
    {
        $id = $this->propose(['kind' => 'goal', 'action' => 'edit', 'data' => [
            'goal_type' => 'muscle_gain', 'target_weight_kg' => 72, 'activity_level' => 'active',
            'training_days_per_week' => 4, 'training_level' => 'beginner', 'training_type' => 'strength',
        ]])->assertCreated()->json('id');

        $this->approve($id)->assertOk();

        $goal = PatientGoal::where('subscriber_id', $this->patient->id)->sole();
        $this->assertSame(['muscle_gain', 'strength', 4], [$goal->goal_type, $goal->training_type, $goal->training_days_per_week]);
        $this->assertSame('weight_gain', $this->patient->fresh()->goal);
        $this->assertSame('active', $this->patient->healthProfile->fresh()->activity_level);

        $other = $this->propose(['kind' => 'goal', 'action' => 'edit', 'data' => ['goal_type' => 'medical_condition', 'training_type' => 'strength']])->assertCreated()->json('id');
        $this->approve($other)->assertOk();
        $this->assertSame(['medical_condition', null], [PatientGoal::sole()->goal_type, PatientGoal::sole()->training_type]);
        $this->assertSame('health_monitoring', $this->patient->fresh()->goal);
    }

    public function test_a_proposal_on_an_item_changed_since_is_stale(): void
    {
        $med = PatientMedication::create(['subscriber_id' => $this->patient->id, 'name' => 'Iron']);
        $id = $this->propose(['kind' => 'medication', 'action' => 'edit', 'target_id' => $med->id, 'data' => ['name' => 'Iron', 'dose' => '65 mg']])->json('id');
        ProfileProposal::whereKey($id)->update(['created_at' => now()->subMinute()]);
        $this->putJson("/api/v1/clients/{$this->patient->id}/medications/{$med->id}", ['name' => 'Iron', 'dose' => '30 mg'], $this->nurse())->assertOk();

        $this->approve($id)->assertStatus(409)->assertJsonPath('code', 'proposal_stale');
        $this->assertSame('30 mg', $med->fresh()->dose);
    }

    public function test_validation_ownership_and_duplicates(): void
    {
        $this->propose(['kind' => 'allergy', 'action' => 'add', 'data' => ['group' => 'other', 'class' => 'avoid']])->assertUnprocessable()->assertJsonValidationErrors('other_text');
        $this->propose(['kind' => 'medication', 'action' => 'add', 'data' => ['name' => 'X', 'status' => 'until']])->assertUnprocessable()->assertJsonValidationErrors('until_date');

        $theirs = PatientAllergy::create(['subscriber_id' => Subscriber::factory()->active()->forNutritionist($this->nutritionist)->create()->id, 'group' => 'egg', 'class' => 'avoid']);
        $this->propose(['kind' => 'allergy', 'action' => 'remove', 'target_id' => $theirs->id])->assertNotFound();

        PatientAllergy::create(['subscriber_id' => $this->patient->id, 'group' => 'egg', 'class' => 'avoid']);
        $this->propose(['kind' => 'allergy', 'action' => 'add', 'data' => ['group' => 'egg', 'class' => 'intolerance']])->assertStatus(409)->assertJsonPath('code', 'already_recorded');

        $stranger = User::factory()->nutritionist()->create();
        $this->getJson("/api/v1/clients/{$this->patient->id}/health-records", $this->bearerFor($stranger))->assertNotFound();
    }

    public function test_a_patient_can_withdraw_a_pending_proposal(): void
    {
        $id = $this->propose(['kind' => 'goal', 'action' => 'edit', 'data' => ['goal_type' => 'other']])->json('id');

        $this->deleteJson("/api/v1/me/proposals/{$id}", [], $this->me())->assertNoContent();
        $this->assertSame('withdrawn', ProfileProposal::find($id)->status);
        $this->deleteJson("/api/v1/me/proposals/{$id}", [], $this->me())->assertStatus(409)->assertJsonPath('code', 'proposal_not_pending');
    }

    public function test_decisions_notify_once_per_batch_with_a_generic_push(): void
    {
        $a = $this->propose(['kind' => 'allergy', 'action' => 'add', 'data' => ['group' => 'sesame', 'class' => 'confirmed_allergy']])->json('id');
        $b = $this->propose(['kind' => 'medication', 'action' => 'add', 'data' => ['name' => 'Warfarin', 'dose' => '5 mg']])->json('id');

        $this->approve($a)->assertOk();
        $this->postJson("/api/v1/clients/{$this->patient->id}/proposals/{$b}/reject", [], $this->nurse())->assertOk();

        $this->assertSame(1, PatientNotification::count());
        $this->assertCount(1, $this->pushes);
        $push = json_encode($this->pushes[0], JSON_UNESCAPED_UNICODE);
        foreach (['Warfarin', 'سمسم', 'sesame'] as $secret) {
            $this->assertStringNotContainsString($secret, $push);
        }
    }

    public function test_the_follow_up_gate_applies(): void
    {
        $id = $this->propose(['kind' => 'goal', 'action' => 'edit', 'data' => ['goal_type' => 'other']])->json('id');
        $this->postJson("/api/v1/clients/{$this->patient->id}/archive", [], $this->nurse())->assertOk();

        $this->getJson('/api/v1/me/health-profile', $this->me())->assertForbidden()->assertJsonPath('code', 'follow_up_ended');
        $this->approve($id)->assertStatus(409)->assertJsonPath('code', 'follow_up_ended');
        $this->getJson("/api/v1/clients/{$this->patient->id}/health-records", $this->nurse())->assertOk();
    }

    public function test_pending_counts_on_the_roster_and_overview(): void
    {
        $this->propose(['kind' => 'goal', 'action' => 'edit', 'data' => ['goal_type' => 'other']])->assertCreated();
        $this->propose(['kind' => 'allergy', 'action' => 'add', 'data' => ['group' => 'soy', 'class' => 'avoid']])->assertCreated();

        $this->getJson('/api/v1/clients', $this->nurse())->assertOk()->assertJsonPath('data.0.pending_proposals_count', 2);
        $this->getJson('/api/v1/dashboard/overview', $this->nurse())->assertOk()->assertJsonPath('pending_proposals', 2);
    }

    // ---- nutritionist edits ---------------------------------------------------

    public function test_nutritionist_edits_apply_directly_and_a_medication_can_be_reviewed(): void
    {
        $med = $this->postJson("/api/v1/clients/{$this->patient->id}/medications", ['name' => 'Levothyroxine', 'timing' => 'empty_stomach'], $this->nurse())->assertCreated()->json();
        $this->postJson("/api/v1/clients/{$this->patient->id}/medications/{$med['id']}/review", ['note' => 'قبل الفطور بنصف ساعة'], $this->nurse())
            ->assertOk()->assertJsonPath('review_note', 'قبل الفطور بنصف ساعة');
        $this->postJson("/api/v1/clients/{$this->patient->id}/allergies", ['group' => 'milk_lactose', 'class' => 'intolerance'], $this->nurse())->assertCreated();

        $this->getJson('/api/v1/me/health-profile', $this->me())->assertOk()
            ->assertJsonPath('medications.0.name', 'Levothyroxine')->assertJsonPath('allergies.0.class', 'intolerance');
    }

    public function test_a_legacy_put_with_the_same_names_keeps_every_structured_field(): void
    {
        $med = PatientMedication::create(['subscriber_id' => $this->patient->id, 'name' => 'Metformin', 'dose' => '500 mg', 'frequency' => 'twice', 'timing' => 'with']);
        $allergy = PatientAllergy::create(['subscriber_id' => $this->patient->id, 'group' => 'other', 'other_text' => 'الفراولة', 'class' => 'intolerance']);
        $milk = PatientAllergy::create(['subscriber_id' => $this->patient->id, 'group' => 'milk_lactose', 'class' => 'avoid']);
        $gone = PatientMedication::create(['subscriber_id' => $this->patient->id, 'name' => 'Old pill']);

        $this->putJson("/api/v1/clients/{$this->patient->id}/health-profile", [
            'weight_kg' => 80, 'height_cm' => 170, 'age' => 30, 'gender' => 'female', 'activity_level' => 'light',
            'medications' => [['name' => 'metformin', 'dose' => 'whatever', 'schedule' => 'x'], ['name' => 'Vitamin D', 'dose' => '1000 IU']],
            'allergies' => ['الفراولة', 'حليب', 'بيض'],
        ], $this->nurse())->assertOk();

        $this->assertSame(['500 mg', 'twice', 'with'], [$med->fresh()->dose, $med->fresh()->frequency, $med->fresh()->timing]);
        $this->assertSame('intolerance', $allergy->fresh()->class);
        $this->assertSame('avoid', $milk->fresh()->class);
        $this->assertNotNull($gone->fresh()->archived_at, 'a name that is really missing is removed');
        $this->assertSame(['Metformin', 'Vitamin D'], PatientMedication::whereNull('archived_at')->orderBy('id')->pluck('name')->all());
        $this->assertSame(['other', 'milk_lactose', 'egg'], PatientAllergy::orderBy('id')->pluck('group')->all());

        // Omitting the arrays never touches the records.
        $this->putJson("/api/v1/clients/{$this->patient->id}/health-profile", [
            'weight_kg' => 81, 'height_cm' => 170, 'age' => 30, 'gender' => 'female', 'activity_level' => 'light',
        ], $this->nurse())->assertOk();
        $this->assertSame(3, PatientAllergy::count());
    }

    // ---- AI draft and foods ------------------------------------------------------

    public function test_the_draft_excludes_foods_by_approved_allergen_group_and_warns_on_a_pending_allergy(): void
    {
        config(['ai.base_url' => null, 'ai.api_key' => null]);
        Http::fake();
        PatientAllergy::create(['subscriber_id' => $this->patient->id, 'group' => 'sesame', 'class' => 'avoid']);
        $sesame = Food::factory()->create(['name_en' => 'Plain dip', 'allergens' => ['sesame'], 'calories_per_100g' => 160]);
        $safe = Food::factory()->create(['name_en' => 'Grilled chicken', 'allergens' => [], 'calories_per_100g' => 165]);

        $draft = $this->postJson("/api/v1/clients/{$this->patient->id}/meal-plans/ai-draft", [], $this->nurse())->assertCreated();
        $used = collect($draft->json('meals'))->flatMap(fn ($m) => $m['items'])->flatMap(fn ($i) => [$i['food']['id'], ...collect($i['alternatives'])->pluck('food.id')]);
        $this->assertNotContains($sesame->id, $used);
        $this->assertContains($safe->id, $used);
        $draft->assertJsonPath('warnings', []);

        $this->propose(['kind' => 'allergy', 'action' => 'add', 'data' => ['group' => 'fish', 'class' => 'confirmed_allergy']])->assertCreated();
        $this->postJson("/api/v1/clients/{$this->patient->id}/meal-plans/ai-draft", [], $this->nurse())->assertCreated()
            ->assertJsonPath('warnings', ['pending_allergy_proposal']);
    }

    public function test_foods_carry_allergens_and_a_section_and_new_foods_are_tagged(): void
    {
        $food = Food::factory()->create(['name_en' => 'Cheese manakish', 'source' => 'admin']);
        $this->assertSame(['milk_lactose', 'wheat_gluten'], $food->allergens);
        $this->assertSame('other', $food->shopping_section);

        $this->getJson('/api/v1/foods/search?q=manakish', $this->nurse())->assertOk()
            ->assertJsonPath('data.0.allergens', ['milk_lactose', 'wheat_gluten'])->assertJsonPath('data.0.shopping_section', 'other');
    }

    public function test_the_backfill_turns_json_into_approved_items(): void
    {
        $migration = require database_path('migrations/2026_10_06_110000_create_patient_health_records_tables.php');
        try {
            $migration->down();
            DB::table('health_profiles')->where('subscriber_id', $this->patient->id)->update([
                'medications' => json_encode([['name' => 'أملوديبين', 'dose' => '5 ملغ', 'schedule' => 'صباحًا']]),
                'allergies' => json_encode(['المكسرات', 'حساسية اللاكتوز', 'الفراولة']),
            ]);
            DB::table('subscribers')->where('id', $this->patient->id)->update(['goal' => 'health_monitoring']);
        } finally {
            $migration->up();
        }

        $this->assertSame('health_energy', PatientGoal::where('subscriber_id', $this->patient->id)->value('goal_type'));
        $this->assertSame(['أملوديبين', '5 ملغ', 'صباحًا'], [PatientMedication::sole()->name, PatientMedication::sole()->dose, PatientMedication::sole()->frequency]);
        $this->assertSame([['tree_nuts', null], ['milk_lactose', null], ['other', 'الفراولة']], PatientAllergy::orderBy('id')->get()->map(fn ($a) => [$a->group, $a->other_text])->all());
        $this->assertSame(['confirmed_allergy'], PatientAllergy::pluck('class')->unique()->values()->all());
    }

    public function test_deleting_the_patient_removes_their_records_and_proposals(): void
    {
        PatientMedication::create(['subscriber_id' => $this->patient->id, 'name' => 'X']);
        $this->propose(['kind' => 'goal', 'action' => 'edit', 'data' => ['goal_type' => 'other']])->assertCreated();

        $this->deleteJson("/api/v1/clients/{$this->patient->id}", [], $this->nurse())->assertNoContent();

        foreach (['patient_goals', 'patient_medications', 'patient_allergies', 'profile_proposals'] as $table) {
            $this->assertSame(0, DB::table($table)->where('subscriber_id', $this->patient->id)->count(), $table);
        }
    }
}
