<?php

namespace Tests\Feature\Audit\Isolation;

use App\Models\PatientConsent;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Auth\JwtService;
use App\Services\Clients\ClientDeletionService;
use App\Services\Clients\FollowUpService;
use App\Services\Reports\ReportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Audit I1. Two clinics (nutritionist A = victim clinic, nutritionist B = attacker clinic),
 * patients in both, and one record of every patient-data type for victim patient A1.
 * Every free-text field of the victim carries MARK, so a response body that contains
 * MARK leaked victim data. Shared by the HTTP matrix and the non-HTTP isolation tests.
 */
final class IsolationWorld
{
    public const MARK = 'ZQVX';

    /** @var array<string, User> */
    public array $users = [];

    /** @var array<string, Subscriber> */
    public array $subs = [];

    /** @var array<string, int|string> victim record ids, by key */
    public array $ids = [];

    /** @var array<string, string> access tokens issued (some before archive/delete) */
    public array $tokens = [];

    public static function build(): self
    {
        $w = new self;
        (new RolesAndPermissionsSeeder)->run();
        config(['medical_reports.enabled' => true]);
        Storage::fake('reports');
        config(['medical_reports.disk' => 'reports']);

        $m = self::MARK;
        $w->users['admin'] = User::factory()->admin()->create(['name' => 'Admin Person']);
        $w->users['nutA'] = User::factory()->nutritionist()->create(['name' => 'Nutritionist Alpha']);
        $w->users['nutB'] = User::factory()->nutritionist()->create(['name' => 'Nutritionist Beta']);

        $w->subs['A1'] = $w->patient('A1', $w->users['nutA'], "{$m} Victim Patient", "{$m}-A1");
        $w->subs['A2'] = $w->patient('A2', $w->users['nutA'], 'Sibling Patient', 'PT-A2');
        $w->subs['A3'] = $w->patient('A3', $w->users['nutA'], 'Archived Patient', 'PT-A3');
        $w->subs['A4'] = $w->patient('A4', $w->users['nutA'], 'Deleted Patient', 'PT-A4');
        $w->subs['B1'] = $w->patient('B1', $w->users['nutB'], 'Other Clinic Patient', 'PT-B1');

        $w->seedVictimRecords();
        $w->seedOwnRecordsFor('B1', $w->users['nutB']);
        $w->seedOwnRecordsFor('A2', $w->users['nutA']);

        foreach (['admin', 'nutA', 'nutB', 'A1', 'A2', 'A3', 'A4', 'B1'] as $k) {
            $w->tokens[$k] = $w->issue($w->users[$k]);
        }

        // Tokens stay in hand; then the follow-up ends / the account is deleted.
        app(FollowUpService::class)->archive($w->subs['A3']->fresh());
        app(ClientDeletionService::class)->delete($w->subs['A4']->fresh(), noticeForNutritionist: true);

        return $w;
    }

    private function issue(User $user): string
    {
        if ($user->hasRole('client')) {
            PatientConsent::query()->firstOrCreate(
                ['user_id' => $user->id, 'version' => config('patient_app.consent.version')],
                ['accepted_at' => now()],
            );
        }

        return app(JwtService::class)->issueAccessToken($user);
    }

    private function patient(string $key, User $nut, string $name, string $code): Subscriber
    {
        $user = User::factory()->client($nut)->create([
            'name' => $name,
            'email' => strtolower($key).'-'.uniqid().'@example.test',
            'username' => strtolower($key).substr(uniqid(), -6),
        ]);
        $this->users[$key] = $user;

        return Subscriber::factory()->create([
            'user_id' => $user->id, 'nutritionist_id' => $nut->id, 'code' => $code,
            'status' => 'active', 'activated_at' => now()->subDays(20), 'goal' => 'weight_loss',
        ]);
    }

    /** Minimal own records so "own parent + victim child" requests have a valid own parent. */
    private function seedOwnRecordsFor(string $key, User $nut): void
    {
        $s = $this->subs[$key];
        DB::table('health_profiles')->insert([
            'subscriber_id' => $s->id, 'weight_kg' => 70, 'height_cm' => 165, 'age' => 30,
            'gender' => 'female', 'activity_level' => 'moderate', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $food = $this->food('Own food '.$key, 'approved', null);
        $this->ids["own{$key}.plan"] = $this->plan($s, $nut, false, 'own plan', $food);
    }

    private function food(string $name, string $status, ?int $by): int
    {
        return DB::table('foods')->insertGetId([
            'source' => $by ? 'nutritionist' : 'admin', 'status' => $status, 'submitted_by' => $by,
            'name_en' => $name, 'name_ar' => $name, 'name_ar_normalized' => $name,
            'calories_per_100g' => 100, 'protein_g_per_100g' => 5, 'carbs_g_per_100g' => 15, 'fat_g_per_100g' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function plan(Subscriber $s, User $nut, bool $template, string $name, int $food, string $status = 'active'): int
    {
        $plan = DB::table('meal_plans')->insertGetId([
            'subscriber_id' => $template ? null : $s->id, 'created_by' => $nut->id, 'is_template' => $template,
            'name' => $name, 'status' => $template ? 'draft' : $status, 'approved_calories' => 1800,
            'plan_summary' => $name.' summary', 'patient_instructions' => $name.' instructions',
            'activated_at' => $status === 'active' && ! $template ? now()->subDays(10) : null,
            'start_date' => now()->subDays(10)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $meal = DB::table('meals')->insertGetId(['meal_plan_id' => $plan, 'name' => 'breakfast', 'created_at' => now(), 'updated_at' => now()]);
        $item = DB::table('meal_items')->insertGetId(['meal_id' => $meal, 'food_id' => $food, 'quantity_grams' => 100, 'created_at' => now(), 'updated_at' => now()]);
        $this->ids["mealItem.{$plan}"] = $item;

        return $plan;
    }

    private function seedVictimRecords(): void
    {
        $m = self::MARK;
        $s = $this->subs['A1'];
        $nutA = $this->users['nutA'];
        $a1 = $this->users['A1'];
        $now = now();
        $t = ['created_at' => $now, 'updated_at' => $now];

        $this->ids['food'] = $this->food("{$m} clinic A custom food", 'pending', $nutA->id);
        $this->ids['foodApproved'] = $this->food('Clinic A approved food', 'approved', $nutA->id);
        $shared = $this->food('Shared rice', 'approved', null);
        $this->ids['sharedFood'] = $shared;

        DB::table('health_profiles')->insert($t + [
            'subscriber_id' => $s->id, 'weight_kg' => 88.8, 'height_cm' => 170, 'age' => 41, 'gender' => 'female',
            'activity_level' => 'light', 'surgery_history' => "{$m} surgery", 'lab_notes' => "{$m} labs",
            'nutritionist_notes' => "{$m} private nutritionist notes",
        ]);
        $this->ids['reading'] = DB::table('body_composition_readings')->insertGetId($t + [
            'subscriber_id' => $s->id, 'recorded_at' => now()->subDays(2)->toDateString(), 'weight_kg' => 88.8, 'source' => 'self-reported',
        ]);
        $this->ids['mealPlan'] = $this->plan($s, $nutA, false, "{$m} active plan", $shared);
        $this->ids['draftPlan'] = $this->plan($s, $nutA, false, "{$m} draft plan", $shared, 'draft');
        $this->ids['template'] = $this->plan($s, $nutA, true, "{$m} template", $shared);
        $this->ids['mealLog'] = DB::table('meal_logs')->insertGetId($t + [
            'subscriber_id' => $s->id, 'food_id' => $shared, 'meal_item_id' => $this->ids["mealItem.{$this->ids['mealPlan']}"],
            'log_kind' => 'planned', 'meal_type' => 'breakfast', 'quantity_grams' => 100, 'logged_at' => now()->subHour(),
        ]);
        $this->ids['wellbeingDate'] = now()->subDay()->toDateString();
        DB::table('patient_wellbeing_logs')->insert($t + ['subscriber_id' => $s->id, 'log_date' => $this->ids['wellbeingDate'], 'mood' => 3, 'note' => "{$m} mood note"]);
        $this->ids['checkin'] = DB::table('patient_checkins')->insertGetId($t + [
            'subscriber_id' => $s->id, 'due_on' => now()->toDateString(), 'status' => 'submitted',
            'answers' => json_encode(['note' => "{$m} checkin answer"]), 'submitted_at' => $now,
        ]);
        $this->ids['openCheckin'] = DB::table('patient_checkins')->insertGetId($t + [
            'subscriber_id' => $s->id, 'due_on' => now()->addDay()->toDateString(), 'status' => 'open',
        ]);
        $this->ids['quickRequest'] = DB::table('patient_quick_requests')->insertGetId($t + [
            'subscriber_id' => $s->id, 'reason' => 'occasion', 'note' => "{$m} quick request note", 'status' => 'open',
        ]);
        $this->ids['allergy'] = DB::table('patient_allergies')->insertGetId($t + ['subscriber_id' => $s->id, 'group' => 'other', 'other_text' => "{$m} allergy", 'class' => 'confirmed_allergy']);
        $this->ids['medication'] = DB::table('patient_medications')->insertGetId($t + ['subscriber_id' => $s->id, 'name' => "{$m} medication", 'status' => 'ongoing']);
        $this->ids['condition'] = DB::table('patient_conditions')->insertGetId($t + ['subscriber_id' => $s->id, 'code' => 'other', 'other_text' => "{$m} condition"]);
        DB::table('patient_goals')->insert($t + ['subscriber_id' => $s->id, 'goal_type' => 'weight_loss', 'details' => "{$m} goal details"]);
        $this->ids['proposal'] = DB::table('profile_proposals')->insertGetId($t + [
            'subscriber_id' => $s->id, 'kind' => 'medication', 'action' => 'add', 'status' => 'pending',
            'payload' => json_encode(['name' => "{$m} proposed med"]),
        ]);
        $this->ids['appointment'] = DB::table('appointments')->insertGetId($t + [
            'subscriber_id' => $s->id, 'nutritionist_id' => $nutA->id, 'type' => 'follow_up', 'channel' => 'phone',
            'starts_at' => now()->addDays(3)->setTime(10, 0), 'ends_at' => now()->addDays(3)->setTime(10, 30),
            'status' => 'booked', 'note' => "{$m} appointment note",
        ]);
        $this->ids['review'] = DB::table('follow_up_reviews')->insertGetId($t + [
            'subscriber_id' => $s->id, 'nutritionist_id' => $nutA->id, 'rating' => 'on_track', 'note' => "{$m} review note",
        ]);
        $this->ids['task'] = DB::table('follow_up_tasks')->insertGetId($t + ['review_id' => $this->ids['review'], 'subscriber_id' => $s->id, 'title' => "{$m} task"]);
        $this->ids['notification'] = DB::table('patient_notifications')->insertGetId($t + [
            'user_id' => $a1->id, 'category' => 'plan', 'type' => 'plan_updated', 'title' => "{$m} notification", 'body' => "{$m} body",
        ]);
        $this->ids['alert'] = DB::table('alerts')->insertGetId($t + ['subscriber_id' => $s->id, 'type' => 'missed_logs', 'severity' => 'warning', 'message' => "{$m} alert message"]);
        $this->ids['post'] = DB::table('nutritionist_posts')->insertGetId($t + [
            'author_id' => $nutA->id, 'title' => "{$m} targeted post", 'body' => "{$m} only for A1", 'audience' => 'patients',
            'status' => 'published', 'published_at' => $now,
        ]);
        DB::table('nutritionist_post_targets')->insert(['post_id' => $this->ids['post'], 'subscriber_id' => $s->id]);
        $this->ids['draftPost'] = DB::table('nutritionist_posts')->insertGetId($t + [
            'author_id' => $nutA->id, 'title' => "{$m} draft post", 'body' => 'draft', 'audience' => 'all', 'status' => 'draft',
        ]);
        DB::table('ai_summaries')->insert($t + ['subscriber_id' => $s->id, 'week_start' => now()->subWeek()->startOfWeek()->toDateString(), 'summary_text' => "{$m} ai summary", 'generated_at' => $now]);
        DB::table('weight_insights')->insert($t + [
            'subscriber_id' => $s->id, 'from_date' => now()->subDays(30)->toDateString(), 'to_date' => now()->toDateString(),
            'facts' => json_encode([]), 'text_ar' => "{$m} insight", 'generated_at' => $now,
        ]);
        DB::table('patient_food_preferences')->insert($t + ['subscriber_id' => $s->id, 'food_id' => $shared, 'kind' => 'dislike', 'source' => 'patient']);
        $this->ids['notice'] = DB::table('patient_deletion_notices')->insertGetId($t + ['nutritionist_id' => $nutA->id, 'patient_code' => "{$m}-DEL", 'deleted_at' => $now]);

        // A real encrypted report, uploaded the real way.
        PatientConsent::query()->create(['user_id' => $a1->id, 'type' => 'medical_reports', 'version' => config('medical_reports.consent.version'), 'accepted_at' => $now]);
        $pdf = UploadedFile::fake()->createWithContent('r.pdf', "%PDF-1.4\n1 0 obj<</Type /Page>>endobj\n{$m} report body\n%%EOF");
        $this->ids['report'] = app(ReportService::class)->upload($s, $a1, $pdf, ['category' => 'lab_test', 'title' => "{$m} report"])->id;
    }
}
