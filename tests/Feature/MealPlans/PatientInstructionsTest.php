<?php

namespace Tests\Feature\MealPlans;

use App\Models\Food;
use App\Models\HealthProfile;
use App\Models\MealPlan;
use App\Models\PatientNotification;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * Clinical batch 1, D: patient_instructions is written FOR the patient (it
 * reaches the client role), unlike plan_summary, which stays private.
 */
class PatientInstructionsTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    private User $client;

    private Subscriber $subscriber;

    private Food $food;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->client = User::factory()->client($this->nutritionist)->create();
        $this->subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $this->client->id]);
        $this->food = Food::factory()->create(['allergens' => []]);

        HealthProfile::create([
            'subscriber_id' => $this->subscriber->id,
            'weight_kg' => 70, 'height_cm' => 170, 'age' => 30, 'gender' => 'female',
            'activity_level' => 'sedentary', 'daily_calorie_needs' => 1800,
        ]);
    }

    private function token(): array
    {
        return $this->bearerFor($this->nutritionist);
    }

    private function plan(array $extra = []): array
    {
        return $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans", $extra + [
            'meals' => [['name' => 'lunch', 'items' => [['food_id' => $this->food->id, 'quantity_grams' => 100]]]],
        ], $this->token())->assertCreated()->json();
    }

    private function activate(int $id): void
    {
        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$id}/activate", [], $this->token())->assertOk();
    }

    public function test_the_patient_sees_instructions_but_never_the_private_summary(): void
    {
        $plan = $this->plan(['patient_instructions' => "قِس مستوى السكر قبل الوجبات.\nمارس المشي.", 'plan_summary' => 'ملاحظة سريرية خاصة']);
        $this->activate($plan['id']);

        $mine = $this->getJson('/api/v1/me/meal-plan', $this->bearerFor($this->client))->assertOk();

        $mine->assertJsonPath('patient_instructions', "قِس مستوى السكر قبل الوجبات.\nمارس المشي.");
        $this->assertArrayNotHasKey('plan_summary', $mine->json());
        $this->assertStringNotContainsString('ملاحظة سريرية خاصة', $mine->getContent());
    }

    public function test_it_is_in_the_nutritionist_plan_data_the_print_view_reads(): void
    {
        $plan = $this->plan(['patient_instructions' => 'تعليمات']);

        $this->getJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$plan['id']}", $this->token())
            ->assertOk()->assertJsonPath('patient_instructions', 'تعليمات');
    }

    public function test_it_is_limited_to_2000_characters_and_optional(): void
    {
        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans", [
            'patient_instructions' => str_repeat('ا', 2001),
            'meals' => [['name' => 'lunch', 'items' => [['food_id' => $this->food->id, 'quantity_grams' => 100]]]],
        ], $this->token())->assertStatus(422)->assertJsonValidationErrors('patient_instructions');

        $plan = $this->plan();
        $this->assertNull($plan['patient_instructions']);
    }

    public function test_editing_it_on_an_active_plan_keeps_the_plan_active_and_notifies_generically(): void
    {
        $plan = $this->plan();
        $this->activate($plan['id']);
        $activatedAt = MealPlan::find($plan['id'])->activated_at;

        $this->patchJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$plan['id']}/decision", ['patient_instructions' => 'قلّل الصوديوم.'], $this->token())
            ->assertOk()->assertJsonPath('status', 'active')->assertJsonPath('patient_instructions', 'قلّل الصوديوم.');

        $fresh = MealPlan::find($plan['id']);
        $this->assertSame('active', $fresh->status);
        $this->assertTrue($activatedAt->equalTo($fresh->activated_at), 'no re-activation');
        $this->assertSame(1, MealPlan::where('subscriber_id', $this->subscriber->id)->count());

        // Same plan, same dedupe key as the activation: one notification, generic text.
        $notes = PatientNotification::where('user_id', $this->client->id)->get();
        $this->assertCount(1, $notes);
        $this->assertStringNotContainsString('الصوديوم', json_encode($notes->toArray(), JSON_UNESCAPED_UNICODE));
    }

    public function test_an_unchanged_value_sends_nothing_and_a_draft_notifies_nobody(): void
    {
        $draft = $this->plan();
        $this->patchJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$draft['id']}/decision", ['patient_instructions' => 'نص'], $this->token())->assertOk();
        $this->assertSame(0, PatientNotification::where('user_id', $this->client->id)->count());

        $this->activate($draft['id']);
        $before = PatientNotification::where('user_id', $this->client->id)->count();

        $this->patchJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$draft['id']}/decision", ['patient_instructions' => 'نص'], $this->token())->assertOk();
        $this->assertSame($before, PatientNotification::where('user_id', $this->client->id)->count());
    }

    public function test_a_duplicate_starts_with_the_same_instructions(): void
    {
        $plan = $this->plan(['patient_instructions' => 'تعليمات']);

        $copy = $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$plan['id']}/duplicate", [], $this->token())->assertCreated();

        $copy->assertJsonPath('patient_instructions', 'تعليمات');
    }
}
