<?php

namespace Tests\Feature\HealthRecords;

use App\Models\HealthProfile;
use App\Models\PatientCondition;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * Clinical batch 1, C: structured conditions are the nutritionist's alone,
 * mirrored into the old health_conditions list, and mapped from free text
 * only by an explicit command.
 */
class ConditionsTest extends TestCase
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
        $this->profile($this->subscriber, ['سكري من النوع الثاني']);
    }

    private function profile(Subscriber $subscriber, array $conditions): HealthProfile
    {
        return HealthProfile::create([
            'subscriber_id' => $subscriber->id,
            'weight_kg' => 70, 'height_cm' => 170, 'age' => 30, 'gender' => 'female',
            'activity_level' => 'sedentary', 'daily_calorie_needs' => 1800, 'health_conditions' => $conditions,
        ]);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/clients/{$this->subscriber->id}/conditions{$suffix}";
    }

    private function token(): array
    {
        return $this->bearerFor($this->nutritionist);
    }

    public function test_the_nutritionist_adds_edits_and_removes_a_condition(): void
    {
        $created = $this->postJson($this->url(), ['code' => 'hypertension', 'note' => 'منذ سنتين'], $this->token())
            ->assertCreated()->assertJsonPath('label', 'ارتفاع ضغط الدم')->assertJsonPath('note', 'منذ سنتين');

        $this->putJson($this->url("/{$created->json('id')}"), ['code' => 'kidney_disease'], $this->token())
            ->assertOk()->assertJsonPath('label', 'أمراض الكلى')->assertJsonPath('note', null);

        $this->getJson($this->url(), $this->token())->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'kidney_disease')
            ->assertJsonCount(10, 'available');

        $this->deleteJson($this->url("/{$created->json('id')}"), [], $this->token())->assertNoContent();
        $this->assertSame(0, PatientCondition::count());
    }

    public function test_other_needs_text_and_the_same_code_cannot_be_added_twice(): void
    {
        $this->postJson($this->url(), ['code' => 'other'], $this->token())->assertStatus(422)->assertJsonValidationErrors('other_text');
        $this->postJson($this->url(), ['code' => 'nonsense'], $this->token())->assertStatus(422)->assertJsonValidationErrors('code');

        $this->postJson($this->url(), ['code' => 'other', 'other_text' => 'فقر دم'], $this->token())->assertCreated()->assertJsonPath('label', 'فقر دم');
        $this->postJson($this->url(), ['code' => 'other', 'other_text' => 'فقر دم'], $this->token())->assertStatus(409);
        $this->postJson($this->url(), ['code' => 'other', 'other_text' => 'ربو'], $this->token())->assertCreated();

        $this->postJson($this->url(), ['code' => 'cancer'], $this->token())->assertCreated();
        $this->postJson($this->url(), ['code' => 'cancer'], $this->token())->assertStatus(409);
    }

    public function test_every_change_is_mirrored_into_the_legacy_list_as_arabic_labels(): void
    {
        $this->postJson($this->url(), ['code' => 'diabetes_type2'], $this->token())->assertCreated();
        $other = $this->postJson($this->url(), ['code' => 'other', 'other_text' => 'فقر دم'], $this->token())->assertCreated();

        $this->assertSame(['سكري النوع الثاني', 'فقر دم'], $this->subscriber->healthProfile->fresh()->health_conditions);

        $this->deleteJson($this->url("/{$other->json('id')}"), [], $this->token())->assertNoContent();

        $this->assertSame(['سكري النوع الثاني'], $this->subscriber->healthProfile->fresh()->health_conditions);
        $this->getJson("/api/v1/clients/{$this->subscriber->id}/health-profile", $this->token())
            ->assertOk()->assertJsonPath('health_conditions', ['سكري النوع الثاني']);
    }

    public function test_an_old_form_cannot_overwrite_structured_conditions_but_unmapped_text_still_saves(): void
    {
        $payload = ['weight_kg' => 70, 'height_cm' => 170, 'age' => 30, 'gender' => 'female', 'activity_level' => 'sedentary'];

        // Nothing structured yet: the free-text list still works as before.
        $this->putJson("/api/v1/clients/{$this->subscriber->id}/health-profile", $payload + ['health_conditions' => ['ربو']], $this->token())->assertOk();
        $this->assertSame(['ربو'], $this->subscriber->healthProfile->fresh()->health_conditions);

        $this->postJson($this->url(), ['code' => 'hypertension'], $this->token())->assertCreated();
        $this->putJson("/api/v1/clients/{$this->subscriber->id}/health-profile", $payload + ['health_conditions' => ['شيء آخر']], $this->token())->assertOk();

        $this->assertSame(['ارتفاع ضغط الدم'], $this->subscriber->healthProfile->fresh()->health_conditions);
    }

    public function test_an_unrelated_save_never_wipes_unmapped_free_text(): void
    {
        $this->postJson("/api/v1/clients/{$this->subscriber->id}/allergies", ['group' => 'egg', 'class' => 'confirmed_allergy'], $this->token())->assertCreated();

        $this->assertSame(['سكري من النوع الثاني'], $this->subscriber->healthProfile->fresh()->health_conditions);
    }

    public function test_ownership_and_the_client_role(): void
    {
        $condition = $this->postJson($this->url(), ['code' => 'cancer'], $this->token())->assertCreated();
        $stranger = $this->bearerFor(User::factory()->nutritionist()->create());

        $this->getJson($this->url(), $stranger)->assertNotFound();
        $this->postJson($this->url(), ['code' => 'cancer'], $stranger)->assertNotFound();
        $this->putJson($this->url("/{$condition->json('id')}"), ['code' => 'liver_disease'], $stranger)->assertNotFound();
        $this->deleteJson($this->url("/{$condition->json('id')}"), [], $stranger)->assertNotFound();
        $this->getJson("/api/v1/clients/{$this->subscriber->id}/patient-tips", $stranger)->assertNotFound();

        $client = User::factory()->client($this->nutritionist)->create();
        $this->subscriber->update(['user_id' => $client->id]);
        $clientToken = $this->bearerFor($client);

        $this->getJson($this->url(), $clientToken)->assertForbidden();
        $this->postJson($this->url(), ['code' => 'cancer'], $clientToken)->assertForbidden();
        $this->putJson($this->url("/{$condition->json('id')}"), ['code' => 'liver_disease'], $clientToken)->assertForbidden();
        $this->deleteJson($this->url("/{$condition->json('id')}"), [], $clientToken)->assertForbidden();
        $this->assertSame(1, PatientCondition::count());
    }

    public function test_an_archived_patient_cannot_be_edited(): void
    {
        $this->subscriber->forceFill(['archived_at' => now()])->save();

        $this->postJson($this->url(), ['code' => 'cancer'], $this->token())->assertStatus(409)->assertJsonPath('code', 'follow_up_ended');
    }

    // ---- map-conditions -----------------------------------------------------

    private function seedFreeText(): array
    {
        $texts = [
            ['سكري من النوع الثاني', 'ضغط'],
            ['مقاومة الإنسولين'],
            ['دهون على الكبد'],
            ['فقر دم'],
            ['سكري الحمل'],
        ];
        $subscribers = [];

        foreach ($texts as $i => $conditions) {
            $s = $i === 0 ? $this->subscriber : Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id]);
            if ($i === 0) {
                $s->healthProfile->update(['health_conditions' => $conditions]);
            } else {
                $this->profile($s, $conditions);
            }
            $subscribers[] = $s;
        }

        return $subscribers;
    }

    public function test_map_conditions_dry_run_changes_nothing(): void
    {
        $this->seedFreeText();
        $before = HealthProfile::pluck('health_conditions', 'id')->toArray();

        $this->artisan('health-records:map-conditions')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(0, PatientCondition::count());
        $this->assertSame($before, HealthProfile::pluck('health_conditions', 'id')->toArray());
    }

    public function test_map_conditions_dry_run_output_has_no_names_or_phones(): void
    {
        $subscribers = $this->seedFreeText();
        $subscribers[0]->update(['name' => 'اسم المريض السري', 'phone' => '+970599123456']);

        $this->artisan('health-records:map-conditions')
            ->doesntExpectOutputToContain('اسم المريض السري')
            ->doesntExpectOutputToContain('+970599123456')
            ->assertSuccessful();
    }

    public function test_map_conditions_apply_maps_by_keyword_and_falls_back_to_other(): void
    {
        [$a, $b, $c, $d, $e] = $this->seedFreeText();

        $this->artisan('health-records:map-conditions --apply')->assertSuccessful();

        $codes = fn (Subscriber $s) => PatientCondition::where('subscriber_id', $s->id)->orderBy('id')->pluck('code')->all();

        $this->assertSame(['diabetes_type2', 'hypertension'], $codes($a));
        $this->assertSame(['insulin_resistance'], $codes($b));
        $this->assertSame(['liver_disease'], $codes($c));
        $this->assertSame(['other'], $codes($d));
        $this->assertSame('فقر دم', PatientCondition::where('subscriber_id', $d->id)->value('other_text'));
        $this->assertSame(['gestational_diabetes'], $codes($e));

        // The old list now holds the canonical labels.
        $this->assertSame(['سكري النوع الثاني', 'ارتفاع ضغط الدم'], $a->healthProfile->fresh()->health_conditions);

        // Running it again changes nothing: those patients are already structured.
        $this->artisan('health-records:map-conditions --apply')->assertSuccessful();
        $this->assertSame(6, PatientCondition::count());
    }

    public function test_map_conditions_leaves_a_patient_who_already_has_structured_conditions(): void
    {
        PatientCondition::create(['subscriber_id' => $this->subscriber->id, 'code' => 'cancer']);

        $this->artisan('health-records:map-conditions --apply')->assertSuccessful();

        $this->assertSame(['cancer'], PatientCondition::where('subscriber_id', $this->subscriber->id)->pluck('code')->all());
    }

    // ---- ready-made tips ------------------------------------------------------

    public function test_tips_follow_the_patients_conditions(): void
    {
        $general = config('patient_tips.general');

        $this->getJson("/api/v1/clients/{$this->subscriber->id}/patient-tips", $this->token())
            ->assertOk()->assertJsonPath('general', $general)->assertJsonCount(0, 'conditions');

        $this->postJson($this->url(), ['code' => 'diabetes_type2'], $this->token())->assertCreated();
        $this->postJson($this->url(), ['code' => 'prediabetes'], $this->token())->assertCreated();
        $this->postJson($this->url(), ['code' => 'hypertension'], $this->token())->assertCreated();

        $response = $this->getJson("/api/v1/clients/{$this->subscriber->id}/patient-tips", $this->token())->assertOk();

        // The shared diabetes lines are listed once, under the first diabetes code.
        $this->assertSame(['diabetes_type2', 'hypertension'], collect($response->json('conditions'))->pluck('code')->all());
        $this->assertCount(3, $response->json('conditions.0.tips'));
        $this->assertSame('قلّل اللحوم الحمراء.', $response->json('conditions.1.tips.2'));
        $this->assertSame('حافظ على هدوئك وخصص وقتًا يوميًا لتمارين التنفس.', $response->json('general.0'));
    }
}
