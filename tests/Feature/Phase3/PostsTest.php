<?php

namespace Tests\Feature\Phase3;

use App\Models\NutritionistPost;
use App\Models\PatientCondition;
use App\Models\PatientNotification;
use App\Models\Subscriber;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AuthenticatesForApi;
use Tests\Concerns\FakesPush;
use Tests\TestCase;

/**
 * Phase 3, A: education posts. Who sees a post is one rule (published, by the
 * patient's own nutritionist, addressed to them); the push goes out once, on
 * the first publish; reads are tracked per patient.
 */
class PostsTest extends TestCase
{
    use AuthenticatesForApi, FakesPush, RefreshDatabase;

    private User $nutritionist;

    private User $otherNutritionist;

    private User $clientA;

    private User $clientB;

    private User $clientC;

    private Subscriber $subA;

    private Subscriber $subB;

    private Subscriber $subC;

    private array $nutritionistToken;

    private array $otherNutritionistToken;

    private array $tokenA;

    private array $tokenB;

    private array $tokenC;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->fakePush();

        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->otherNutritionist = User::factory()->nutritionist()->create();

        // A: hypertension. B: no condition. C: belongs to the other nutritionist.
        $this->clientA = User::factory()->client($this->nutritionist)->create(['fcm_token' => 'device-a']);
        $this->clientB = User::factory()->client($this->nutritionist)->create(['fcm_token' => 'device-b']);
        $this->clientC = User::factory()->client($this->otherNutritionist)->create(['fcm_token' => 'device-c']);

        $this->subA = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $this->clientA->id]);
        $this->subB = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $this->clientB->id]);
        $this->subC = Subscriber::factory()->active()->create(['nutritionist_id' => $this->otherNutritionist->id, 'user_id' => $this->clientC->id]);

        PatientCondition::create(['subscriber_id' => $this->subA->id, 'code' => 'hypertension', 'created_by' => $this->nutritionist->id]);

        $this->nutritionistToken = $this->bearerFor($this->nutritionist);
        $this->otherNutritionistToken = $this->bearerFor($this->otherNutritionist);
        $this->tokenA = $this->bearerFor($this->clientA);
        $this->tokenB = $this->bearerFor($this->clientB);
        $this->tokenC = $this->bearerFor($this->clientC);

        // 10:00 clinic time: outside any quiet hours.
        $this->travelTo(CarbonImmutable::parse('2026-10-26 10:00', config('scheduling.timezone')));
    }

    /** @param  array<string, mixed>  $extra */
    private function draft(array $extra = [], ?array $token = null): array
    {
        return $this->postJson('/api/v1/posts', $extra + ['title' => 'اشرب مي', 'body' => 'اشرب مي كفاية كل يوم.', 'audience' => 'all'], $token ?? $this->nutritionistToken)
            ->assertCreated()->json();
    }

    private function published(array $extra = [], ?array $token = null): array
    {
        $post = $this->draft($extra, $token);
        $this->postJson("/api/v1/posts/{$post['id']}/publish", [], $token ?? $this->nutritionistToken)->assertOk();

        return $post;
    }

    /** @return list<int> the post ids a patient sees */
    private function feed(array $token): array
    {
        return array_column($this->getJson('/api/v1/me/posts', $token)->assertOk()->json('data'), 'id');
    }

    // ---- visibility ----------------------------------------------------------------------------------------

    public function test_the_visibility_rule_for_each_audience(): void
    {
        $all = $this->published();
        $hypertension = $this->published(['audience' => 'conditions', 'condition_codes' => ['hypertension']]);
        $celiac = $this->published(['audience' => 'conditions', 'condition_codes' => ['celiac']]);
        $either = $this->published(['audience' => 'conditions', 'condition_codes' => ['celiac', 'hypertension']]);
        $targetedB = $this->published(['audience' => 'patients', 'patient_ids' => [$this->subB->id]]);

        $seenByA = $this->feed($this->tokenA);
        $this->assertEqualsCanonicalizing([$all['id'], $hypertension['id'], $either['id']], $seenByA, 'all + matching conditions, not the celiac one, not the one aimed at B');

        $seenByB = $this->feed($this->tokenB);
        $this->assertEqualsCanonicalizing([$all['id'], $targetedB['id']], $seenByB, 'no condition: only all + named');
        $this->assertNotContains($celiac['id'], $seenByB);
    }

    public function test_a_draft_and_an_unpublished_post_are_hidden(): void
    {
        $draft = $this->draft();
        $this->assertSame([], $this->feed($this->tokenA));
        $this->getJson("/api/v1/me/posts/{$draft['id']}", $this->tokenA)->assertNotFound();

        $this->postJson("/api/v1/posts/{$draft['id']}/publish", [], $this->nutritionistToken)->assertOk()->assertJsonPath('status', 'published');
        $this->assertSame([$draft['id']], $this->feed($this->tokenA));

        $this->postJson("/api/v1/posts/{$draft['id']}/unpublish", [], $this->nutritionistToken)->assertOk()->assertJsonPath('status', 'draft');
        $this->assertSame([], $this->feed($this->tokenA));
        $this->getJson("/api/v1/me/posts/{$draft['id']}", $this->tokenA)->assertNotFound();
    }

    public function test_another_nutritionists_posts_are_never_seen_and_never_reach_my_patients(): void
    {
        $mine = $this->published();
        $theirs = $this->published(['audience' => 'all'], $this->otherNutritionistToken);

        $this->assertSame([$mine['id']], $this->feed($this->tokenA));
        $this->assertSame([$theirs['id']], $this->feed($this->tokenC));

        $this->getJson("/api/v1/me/posts/{$theirs['id']}", $this->tokenA)->assertNotFound();
        $this->postJson("/api/v1/me/posts/{$theirs['id']}/read", [], $this->tokenA)->assertNotFound();
        $this->assertSame(0, DB::table('nutritionist_post_reads')->count());
    }

    public function test_a_post_not_addressed_to_the_patient_is_a_404_not_a_403(): void
    {
        $targetedB = $this->published(['audience' => 'patients', 'patient_ids' => [$this->subB->id]]);

        $this->getJson("/api/v1/me/posts/{$targetedB['id']}", $this->tokenA)->assertNotFound();
        $this->getJson("/api/v1/me/posts/{$targetedB['id']}", $this->tokenB)->assertOk()->assertJsonPath('id', $targetedB['id']);
    }

    public function test_a_patient_with_no_recorded_condition_never_matches_a_conditions_post(): void
    {
        $this->published(['audience' => 'conditions', 'condition_codes' => ['hypertension', 'celiac', 'prediabetes']]);

        $this->assertSame([], $this->feed($this->tokenB));
    }

    // ---- the feed ----------------------------------------------------------------------------------------------

    public function test_the_feed_is_paginated_pinned_first_then_newest(): void
    {
        $old = $this->published(['title' => 'old']);
        $this->travel(1)->hours();
        $pinned = $this->published(['title' => 'pinned', 'pinned' => true]);
        $this->travel(1)->hours();
        $new = $this->published(['title' => 'new']);

        $response = $this->getJson('/api/v1/me/posts', $this->tokenA)->assertOk();

        $response->assertJsonStructure(['data' => [['id', 'title', 'body', 'published_at', 'pinned', 'is_read']], 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
        $this->assertSame([$pinned['id'], $new['id'], $old['id']], array_column($response->json('data'), 'id'));
        $this->assertSame(['id', 'title', 'body', 'published_at', 'pinned', 'is_read'], array_keys($response->json('data.0')), 'nothing else leaks to the patient');
    }

    // ---- push -------------------------------------------------------------------------------------------------------

    public function test_the_first_publish_pushes_one_generic_message_to_each_audience_member_and_never_again(): void
    {
        $post = $this->draft(['title' => 'سر الطبخة', 'body' => 'نص النصيحة الخاص']);
        $this->assertSame([], $this->pushes, 'a draft pushes nothing');

        $this->postJson("/api/v1/posts/{$post['id']}/publish", [], $this->nutritionistToken)->assertOk();

        $this->assertEqualsCanonicalizing(['device-a', 'device-b'], array_column($this->pushes, 'token'), 'my two patients, not the other nutritionist\'s');
        $this->assertSame('نصيحة جديدة من أخصائيتك', $this->pushes[0]['body']);
        $this->assertSame('education_post', $this->pushes[0]['data']['type']);
        $this->assertStringNotContainsString('سر الطبخة', json_encode($this->pushes, JSON_UNESCAPED_UNICODE), 'no post content in the push');
        $this->assertStringNotContainsString('نص النصيحة', json_encode($this->pushes, JSON_UNESCAPED_UNICODE));

        // Publishing again, unpublish + publish, and editing a published post: no new push.
        $this->postJson("/api/v1/posts/{$post['id']}/publish", [], $this->nutritionistToken)->assertOk();
        $this->postJson("/api/v1/posts/{$post['id']}/unpublish", [], $this->nutritionistToken)->assertOk();
        $this->putJson("/api/v1/posts/{$post['id']}", ['body' => 'نص معدّل'], $this->nutritionistToken)->assertOk();
        $this->postJson("/api/v1/posts/{$post['id']}/publish", [], $this->nutritionistToken)->assertOk();

        $this->assertCount(2, $this->pushes);
        $this->assertSame(2, PatientNotification::where('type', 'education_post')->count());
        $this->assertSame(CarbonImmutable::now()->toDateString(), NutritionistPost::find($post['id'])->published_at->toDateString());
    }

    public function test_the_push_goes_only_to_the_audience(): void
    {
        $this->published(['audience' => 'conditions', 'condition_codes' => ['hypertension']]);
        $this->assertSame(['device-a'], array_column($this->pushes, 'token'));

        $this->pushes = [];
        $this->published(['audience' => 'patients', 'patient_ids' => [$this->subB->id]]);
        $this->assertSame(['device-b'], array_column($this->pushes, 'token'));
    }

    public function test_an_archived_patient_is_not_pushed(): void
    {
        $this->subB->forceFill(['archived_at' => now()])->save();

        $this->published();

        $this->assertSame(['device-a'], array_column($this->pushes, 'token'));
    }

    public function test_a_patient_who_switched_education_off_gets_the_inbox_row_but_no_push(): void
    {
        $this->putJson('/api/v1/me/notification-preferences', ['education' => false], $this->tokenA)->assertOk()->assertJsonPath('categories.education', false);

        $post = $this->published();

        $this->assertSame(['device-b'], array_column($this->pushes, 'token'));
        $row = PatientNotification::where('user_id', $this->clientA->id)->where('type', 'education_post')->first();
        $this->assertNotNull($row, 'the inbox keeps it');
        $this->assertSame('category_off', $row->push_skipped);
        $this->assertSame('education', $row->category);
        $this->assertSame($post['id'], (int) $row->data['post_id']);
    }

    public function test_the_new_preference_categories_default_on(): void
    {
        $this->getJson('/api/v1/me/notification-preferences', $this->tokenA)->assertOk()
            ->assertJsonPath('categories.education', true)->assertJsonPath('categories.morning', true);
    }

    // ---- reads ----------------------------------------------------------------------------------------------------------

    public function test_reading_is_tracked_per_patient_and_drives_the_unread_count(): void
    {
        $one = $this->published(['title' => 'one']);
        $two = $this->published(['title' => 'two']);

        $this->getJson('/api/v1/me/posts/unread-count', $this->tokenA)->assertOk()->assertExactJson(['unread' => 2]);

        $this->getJson("/api/v1/me/posts/{$one['id']}", $this->tokenA)->assertOk()->assertJsonPath('is_read', false);
        $this->postJson("/api/v1/me/posts/{$one['id']}/read", [], $this->tokenA)->assertOk()->assertExactJson(['id' => $one['id'], 'is_read' => true]);
        // Idempotent.
        $this->postJson("/api/v1/me/posts/{$one['id']}/read", [], $this->tokenA)->assertOk();

        $this->assertSame(1, DB::table('nutritionist_post_reads')->count());
        $this->getJson('/api/v1/me/posts/unread-count', $this->tokenA)->assertExactJson(['unread' => 1]);
        $this->getJson('/api/v1/me/posts/unread-count', $this->tokenB)->assertExactJson(['unread' => 2], 'B has read nothing');

        $byId = collect($this->getJson('/api/v1/me/posts', $this->tokenA)->json('data'))->keyBy('id');
        $this->assertTrue($byId[$one['id']]['is_read']);
        $this->assertFalse($byId[$two['id']]['is_read']);
    }

    public function test_the_first_read_time_is_kept(): void
    {
        $post = $this->published();
        $this->postJson("/api/v1/me/posts/{$post['id']}/read", [], $this->tokenA)->assertOk();
        $first = DB::table('nutritionist_post_reads')->value('read_at');

        $this->travel(2)->hours();
        $this->postJson("/api/v1/me/posts/{$post['id']}/read", [], $this->tokenA)->assertOk();

        $this->assertSame($first, DB::table('nutritionist_post_reads')->value('read_at'));
    }

    public function test_a_hidden_post_stops_counting_as_unread(): void
    {
        $post = $this->published();
        $this->postJson("/api/v1/posts/{$post['id']}/unpublish", [], $this->nutritionistToken)->assertOk();

        $this->getJson('/api/v1/me/posts/unread-count', $this->tokenA)->assertExactJson(['unread' => 0]);
    }

    // ---- stats -------------------------------------------------------------------------------------------------------------

    public function test_stats_count_the_audience_and_those_who_read(): void
    {
        $all = $this->published();
        $this->getJson("/api/v1/posts/{$all['id']}/stats", $this->nutritionistToken)->assertOk()->assertExactJson(['audience_count' => 2, 'read_count' => 0]);

        $this->postJson("/api/v1/me/posts/{$all['id']}/read", [], $this->tokenA)->assertOk();
        $this->getJson("/api/v1/posts/{$all['id']}/stats", $this->nutritionistToken)->assertExactJson(['audience_count' => 2, 'read_count' => 1]);

        $conditions = $this->published(['audience' => 'conditions', 'condition_codes' => ['hypertension']]);
        $this->getJson("/api/v1/posts/{$conditions['id']}/stats", $this->nutritionistToken)->assertExactJson(['audience_count' => 1, 'read_count' => 0]);

        $named = $this->published(['audience' => 'patients', 'patient_ids' => [$this->subA->id, $this->subB->id]]);
        $this->getJson("/api/v1/posts/{$named['id']}/stats", $this->nutritionistToken)->assertExactJson(['audience_count' => 2, 'read_count' => 0]);

        $this->assertSame(1, $this->readCountOf($all['id']), 'the list carries the read count too');
    }

    private function readCountOf(int $id): int
    {
        return collect($this->getJson('/api/v1/posts', $this->nutritionistToken)->json('data'))->firstWhere('id', $id)['read_count'];
    }

    // ---- ownership -----------------------------------------------------------------------------------------------------------

    public function test_every_post_action_is_for_the_author_only(): void
    {
        $post = $this->published();
        $other = $this->otherNutritionistToken;

        $this->getJson("/api/v1/posts/{$post['id']}", $other)->assertNotFound();
        $this->putJson("/api/v1/posts/{$post['id']}", ['title' => 'hijack'], $other)->assertNotFound();
        $this->deleteJson("/api/v1/posts/{$post['id']}", [], $other)->assertNotFound();
        $this->postJson("/api/v1/posts/{$post['id']}/publish", [], $other)->assertNotFound();
        $this->postJson("/api/v1/posts/{$post['id']}/unpublish", [], $other)->assertNotFound();
        $this->getJson("/api/v1/posts/{$post['id']}/stats", $other)->assertNotFound();

        $this->assertSame([], $this->getJson('/api/v1/posts', $other)->json('data'));
        $this->assertSame('اشرب مي', NutritionistPost::find($post['id'])->title);
    }

    public function test_a_patient_cannot_use_the_nutritionist_endpoints(): void
    {
        $post = $this->published();

        $this->getJson('/api/v1/posts', $this->tokenA)->assertForbidden();
        $this->postJson('/api/v1/posts', ['title' => 'x', 'body' => 'y', 'audience' => 'all'], $this->tokenA)->assertForbidden();
        $this->postJson("/api/v1/posts/{$post['id']}/publish", [], $this->tokenA)->assertForbidden();
    }

    public function test_a_nutritionist_cannot_use_the_patient_endpoints(): void
    {
        $this->getJson('/api/v1/me/posts', $this->nutritionistToken)->assertForbidden();
        $this->getJson('/api/v1/me/posts/unread-count', $this->nutritionistToken)->assertForbidden();
    }

    public function test_targets_must_be_my_own_patients(): void
    {
        $this->postJson('/api/v1/posts', ['title' => 'x', 'body' => 'y', 'audience' => 'patients', 'patient_ids' => [$this->subC->id]], $this->nutritionistToken)
            ->assertStatus(422)->assertJsonValidationErrors(['patient_ids.0']);
        $this->assertSame(0, NutritionistPost::count());
    }

    // ---- validation / editing --------------------------------------------------------------------------------------------------

    public function test_validation(): void
    {
        $base = ['title' => 'عنوان', 'body' => 'نص', 'audience' => 'all'];
        $t = $this->nutritionistToken;

        $this->postJson('/api/v1/posts', ['title' => str_repeat('ع', 121)] + $base, $t)->assertStatus(422)->assertJsonValidationErrors('title');
        $this->postJson('/api/v1/posts', ['title' => str_repeat('ع', 120)] + $base, $t)->assertCreated();
        $this->postJson('/api/v1/posts', ['body' => str_repeat('ن', 2001)] + $base, $t)->assertStatus(422)->assertJsonValidationErrors('body');
        $this->postJson('/api/v1/posts', ['body' => str_repeat('ن', 2000)] + $base, $t)->assertCreated();
        $this->postJson('/api/v1/posts', ['audience' => 'everyone'] + $base, $t)->assertStatus(422)->assertJsonValidationErrors('audience');
        $this->postJson('/api/v1/posts', ['audience' => 'conditions'] + $base, $t)->assertStatus(422)->assertJsonValidationErrors('condition_codes');
        $this->postJson('/api/v1/posts', ['audience' => 'conditions', 'condition_codes' => ['made_up']] + $base, $t)->assertStatus(422)->assertJsonValidationErrors('condition_codes.0');
        $this->postJson('/api/v1/posts', ['audience' => 'conditions', 'condition_codes' => ['other']] + $base, $t)->assertStatus(422)->assertJsonValidationErrors('condition_codes.0');
        $this->postJson('/api/v1/posts', ['audience' => 'patients'] + $base, $t)->assertStatus(422)->assertJsonValidationErrors('patient_ids');
        $this->postJson('/api/v1/posts', ['title' => ''] + $base, $t)->assertStatus(422)->assertJsonValidationErrors('title');
    }

    public function test_changing_the_audience_clears_what_no_longer_applies(): void
    {
        $post = $this->draft(['audience' => 'patients', 'patient_ids' => [$this->subA->id]]);
        $this->assertSame([$this->subA->id], $post['patient_ids']);
        $this->assertNull($post['condition_codes']);

        $toConditions = $this->putJson("/api/v1/posts/{$post['id']}", ['audience' => 'conditions', 'condition_codes' => ['celiac']], $this->nutritionistToken)->assertOk();
        $toConditions->assertJsonPath('condition_codes', ['celiac'])->assertJsonPath('patient_ids', null);
        $this->assertSame(0, DB::table('nutritionist_post_targets')->count());

        $toAll = $this->putJson("/api/v1/posts/{$post['id']}", ['audience' => 'all'], $this->nutritionistToken)->assertOk();
        $toAll->assertJsonPath('condition_codes', null)->assertJsonPath('patient_ids', null);
    }

    public function test_editing_only_the_title_keeps_the_audience(): void
    {
        $post = $this->draft(['audience' => 'conditions', 'condition_codes' => ['hypertension', 'celiac']]);

        $this->putJson("/api/v1/posts/{$post['id']}", ['title' => 'عنوان جديد'], $this->nutritionistToken)->assertOk()
            ->assertJsonPath('title', 'عنوان جديد')->assertJsonPath('audience', 'conditions')->assertJsonPath('condition_codes', ['hypertension', 'celiac']);

        $named = $this->draft(['audience' => 'patients', 'patient_ids' => [$this->subA->id, $this->subB->id]]);
        $this->putJson("/api/v1/posts/{$named['id']}", ['pinned' => true], $this->nutritionistToken)->assertOk()
            ->assertJsonPath('pinned', true)->assertJsonPath('patient_ids', [$this->subA->id, $this->subB->id]);
    }

    public function test_deleting_a_post_removes_its_targets_and_reads(): void
    {
        $post = $this->published(['audience' => 'patients', 'patient_ids' => [$this->subA->id]]);
        $this->postJson("/api/v1/me/posts/{$post['id']}/read", [], $this->tokenA)->assertOk();

        $this->deleteJson("/api/v1/posts/{$post['id']}", [], $this->nutritionistToken)->assertNoContent();

        $this->assertSame(0, NutritionistPost::count());
        $this->assertSame(0, DB::table('nutritionist_post_targets')->count());
        $this->assertSame(0, DB::table('nutritionist_post_reads')->count());
        $this->assertSame([], $this->feed($this->tokenA));
    }

    // ---- plain text ----------------------------------------------------------------------------------------------------------------

    public function test_markup_is_stored_and_served_as_inert_text(): void
    {
        $body = "<script>alert('x')</script> **bold** <b>tag</b> & <3\nسطر ثانٍ";
        $post = $this->published(['title' => '<img src=x onerror=alert(1)>', 'body' => $body]);

        $response = $this->getJson("/api/v1/me/posts/{$post['id']}", $this->tokenA)->assertOk();

        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
        // Nothing is rewritten, rendered or interpreted: the client shows it as text.
        $this->assertSame($body, $response->json('body'));
        $this->assertSame('<img src=x onerror=alert(1)>', $response->json('title'));
        // And it is a JSON string value, never markup in a response body.
        $this->assertStringStartsWith('{', $response->getContent());
    }

    // ---- templates ---------------------------------------------------------------------------------------------------------------------

    public function test_templates_list_the_ready_made_tips_with_the_conditions_they_belong_to(): void
    {
        $templates = $this->getJson('/api/v1/posts/templates', $this->nutritionistToken)->assertOk()->json();

        $this->assertNotEmpty($templates);
        foreach ($templates as $template) {
            $this->assertSame(['text', 'condition_codes'], array_keys($template));
            $this->assertNotSame('', $template['text']);
            $this->assertLessThanOrEqual(NutritionistPost::BODY_MAX, mb_strlen($template['text']));
        }

        $texts = array_column($templates, 'text');
        $this->assertSame($texts, array_values(array_unique($texts)), 'a tip shared by several conditions is listed once');

        $diabetes = collect($templates)->first(fn ($t) => in_array('diabetes_type1', $t['condition_codes'], true));
        $this->assertContains('diabetes_type2', $diabetes['condition_codes']);

        $general = collect($templates)->firstWhere('text', config('patient_tips.general.0'));
        $this->assertSame([], $general['condition_codes']);
    }

    public function test_a_template_line_becomes_a_draft_through_the_ordinary_create(): void
    {
        $line = config('patient_tips.hypertension.0');

        $this->postJson('/api/v1/posts', ['title' => 'الصوديوم', 'body' => $line, 'audience' => 'conditions', 'condition_codes' => ['hypertension']], $this->nutritionistToken)
            ->assertCreated()->assertJsonPath('status', 'draft')->assertJsonPath('body', $line);
    }
}
