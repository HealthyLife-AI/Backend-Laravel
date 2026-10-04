<?php

namespace Tests\Feature\FollowUp;

use App\Models\FollowUpReview;
use App\Models\NotificationPreference;
use App\Models\PatientNotification;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AuthenticatesForApi;
use Tests\Concerns\FakesPush;
use Tests\TestCase;

/** Follow-up between sessions: reviews, «فهمت», tasks, and the notifications they send. */
class ReviewTest extends TestCase
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
        $this->patient = Subscriber::factory()->active()->forNutritionist($this->nutritionist)->create();
        $this->patient->user->forceFill(['fcm_token' => 'device-1'])->save();
        // Quiet hours are covered in NotificationCenterTest; here a push must never depend on the clock.
        NotificationPreference::for($this->patient->user)->update(['quiet_hours_enabled' => false]);
    }

    private function nurse(): array
    {
        return $this->bearerFor($this->nutritionist);
    }

    private function me(): array
    {
        return $this->bearerFor($this->patient->user);
    }

    private function review(array $body = []): TestResponse
    {
        return $this->postJson("/api/v1/clients/{$this->patient->id}/reviews", $body + [
            'rating' => 'small_adjustment',
            'note' => 'وزنك نزل 1.2 كغ، قلل الخبز بالعشاء.',
            'key_points' => ['اشرب ماء أكثر', ''],
            'tasks' => [['title' => 'مشي 15 دقيقة بعد العشاء'], ['title' => 'لترين ماء يوميًا']],
        ], $this->nurse());
    }

    public function test_a_review_is_created_read_by_the_patient_and_notifies_once_with_a_generic_push(): void
    {
        $id = $this->review()->assertCreated()
            ->assertJsonPath('rating', 'small_adjustment')
            ->assertJsonPath('key_points', ['اشرب ماء أكثر'])
            ->assertJsonCount(2, 'tasks')
            ->assertJsonPath('acknowledged_at', null)
            ->json('id');

        $this->getJson('/api/v1/me/reviews', $this->me())->assertOk()->assertJsonPath('0.id', $id)->assertJsonPath('0.note', 'وزنك نزل 1.2 كغ، قلل الخبز بالعشاء.');

        $this->assertSame(1, PatientNotification::count());
        $this->assertCount(1, $this->pushes);
        $push = json_encode($this->pushes[0], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('لديك ملاحظة جديدة من أخصائيك', $push);
        $this->assertStringNotContainsString('1.2', $push, 'no health detail in the FCM payload');
        $this->assertStringNotContainsString('الخبز', $push);
        $this->assertSame((string) $id, $this->pushes[0]['data']['review_id']);
    }

    public function test_an_empty_review_and_an_overlong_note_are_refused(): void
    {
        $this->postJson("/api/v1/clients/{$this->patient->id}/reviews", ['key_points' => ['  ']], $this->nurse())
            ->assertUnprocessable()->assertJsonPath('code', 'review_empty');
        $this->review(['note' => str_repeat('ا', 2001)])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->review(['rating' => 'excellent'])->assertUnprocessable()->assertJsonValidationErrors('rating');
        $this->assertSame(0, FollowUpReview::count());
    }

    public function test_acknowledge_and_task_done_are_safe_to_repeat_and_visible_to_the_nutritionist(): void
    {
        $review = $this->review()->json();
        $task = $review['tasks'][0]['id'];

        $this->travelTo(now()->subMinutes(5));
        $first = $this->postJson("/api/v1/me/reviews/{$review['id']}/acknowledge", [], $this->me())->assertOk()->json('acknowledged_at');
        $this->travelBack();
        $this->postJson("/api/v1/me/reviews/{$review['id']}/acknowledge", [], $this->me())->assertOk()->assertJsonPath('acknowledged_at', $first);

        $this->postJson("/api/v1/me/tasks/{$task}/done", [], $this->me())->assertOk();
        $this->postJson("/api/v1/me/tasks/{$task}/done", [], $this->me())->assertOk();

        $seen = $this->getJson("/api/v1/clients/{$this->patient->id}/reviews", $this->nurse())->assertOk()->json('0');
        $this->assertSame($first, $seen['acknowledged_at']);
        $this->assertNotNull($seen['tasks'][0]['done_at']);
        $this->assertNull($seen['tasks'][1]['done_at']);

        $this->deleteJson("/api/v1/me/tasks/{$task}/done", [], $this->me())->assertOk()->assertJsonPath('done_at', null);
    }

    public function test_editing_after_acknowledgement_clears_it_and_notifies_once_keeping_done_tasks(): void
    {
        $this->travelTo(now()->subMinutes(10));
        $review = $this->review()->json();
        [$walk, $water] = array_column($review['tasks'], 'id');
        $this->postJson("/api/v1/me/reviews/{$review['id']}/acknowledge", [], $this->me())->assertOk();
        $this->postJson("/api/v1/me/tasks/{$walk}/done", [], $this->me())->assertOk();
        $this->travelBack();

        $edit = fn (string $note) => $this->putJson("/api/v1/clients/{$this->patient->id}/reviews/{$review['id']}", [
            'rating' => 'on_track', 'note' => $note,
            'tasks' => [['id' => $walk, 'title' => 'مشي 15 دقيقة بعد العشاء'], ['title' => 'نوم 7 ساعات']],
        ], $this->nurse());

        $updated = $edit('أحسنت')->assertOk()->json();
        $this->assertNull($updated['acknowledged_at']);
        $this->assertNotNull($updated['edited_at']);
        $this->assertNotNull($updated['tasks'][0]['done_at'], 'a done task stays done');
        $this->assertNotContains($water, array_column($updated['tasks'], 'id'), 'a task left out is removed');
        $this->assertSame(['review_new', 'review_updated'], PatientNotification::orderBy('id')->pluck('type')->all());

        // Edited again before the patient looks: no new notification.
        $edit('أحسنت جدًا')->assertOk();
        $this->assertSame(2, PatientNotification::count());
        $this->assertCount(2, $this->pushes);
    }

    public function test_another_patients_review_or_task_is_a_404_and_another_nutritionist_cant_write(): void
    {
        $review = $this->review()->json();
        $other = Subscriber::factory()->active()->forNutritionist($this->nutritionist)->create();
        $stranger = User::factory()->nutritionist()->create();

        $this->postJson("/api/v1/me/reviews/{$review['id']}/acknowledge", [], $this->bearerFor($other->user))->assertNotFound();
        $this->postJson("/api/v1/me/tasks/{$review['tasks'][0]['id']}/done", [], $this->bearerFor($other->user))->assertNotFound();
        $this->getJson('/api/v1/me/reviews', $this->bearerFor($other->user))->assertOk()->assertJsonCount(0);

        $this->getJson("/api/v1/clients/{$this->patient->id}/reviews", $this->bearerFor($stranger))->assertNotFound();
        $this->putJson("/api/v1/clients/{$this->patient->id}/reviews/{$review['id']}", ['note' => 'x'], $this->bearerFor($stranger))->assertNotFound();
        $this->deleteJson("/api/v1/clients/{$this->patient->id}/reviews/{$review['id']}", [], $this->bearerFor($stranger))->assertNotFound();
    }

    public function test_while_follow_up_has_ended_the_nutritionist_gets_409_and_the_patient_403(): void
    {
        $review = $this->review()->json();
        $this->postJson("/api/v1/clients/{$this->patient->id}/archive", [], $this->nurse())->assertOk();

        $this->review()->assertStatus(409)->assertJsonPath('code', 'follow_up_ended');
        $this->getJson("/api/v1/clients/{$this->patient->id}/reviews", $this->nurse())->assertOk();

        foreach ([
            ['GET', '/api/v1/me/reviews'],
            ['POST', "/api/v1/me/reviews/{$review['id']}/acknowledge"],
            ['POST', "/api/v1/me/tasks/{$review['tasks'][0]['id']}/done"],
            ['GET', '/api/v1/me/notifications'],
            ['GET', '/api/v1/me/notification-preferences'],
        ] as [$method, $url]) {
            $this->json($method, $url, [], $this->me())->assertForbidden()->assertJsonPath('code', 'follow_up_ended');
        }
    }

    public function test_deleting_a_review_removes_its_tasks(): void
    {
        $review = $this->review()->json();

        $this->deleteJson("/api/v1/clients/{$this->patient->id}/reviews/{$review['id']}", [], $this->nurse())->assertNoContent();

        $this->assertDatabaseCount('follow_up_reviews', 0);
        $this->assertDatabaseCount('follow_up_tasks', 0);
    }

    public function test_removing_the_patient_deletes_their_reviews_notifications_and_preferences(): void
    {
        $this->review()->assertCreated();
        $this->getJson('/api/v1/me/notification-preferences', $this->me())->assertOk();

        $this->deleteJson("/api/v1/clients/{$this->patient->id}", [], $this->nurse())->assertNoContent();

        foreach (['follow_up_reviews', 'follow_up_tasks', 'patient_notifications', 'notification_preferences'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
