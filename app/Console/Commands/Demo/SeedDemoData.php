<?php

namespace App\Console\Commands\Demo;

use App\Models\Food;
use App\Models\MealItem;
use App\Models\MealPlan;
use App\Models\NutritionistAvailability;
use App\Models\PatientCheckin;
use App\Models\PatientGoal;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Adherence\AdherenceService;
use App\Services\Alerts\AlertEvaluationService;
use App\Services\Appointments\AppointmentService;
use App\Services\Checkins\CheckinService;
use App\Services\Clients\ClientCodeAllocator;
use App\Services\Clients\ClientDeletionService;
use App\Services\Consent\ConsentService;
use App\Services\HealthRecords\HealthRecordService;
use App\Services\Logs\PatientEntryService;
use App\Services\MealPlans\MealPlanService;
use App\Services\Measurements\MeasurementScheduleService;
use App\Services\Nutrition\MealPlanCalculatorService;
use App\Services\Nutrition\NutritionCalculatorService;
use App\Services\Posts\PostService;
use App\Services\QuickRequests\QuickRequestService;
use App\Services\Wellbeing\WellbeingService;
use App\Support\ClinicDay;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * Clearly fake demo data for app-store reviewers, store screenshots, the marketing video and
 * live demos: one demo nutritionist and four demo patients, with history that always ends
 * yesterday (every date is relative to today).
 *
 * THE MARKER: `users.is_demo = true` on every demo account (nutritionist and patients), plus
 * reserved identifiers: the nutritionist's e-mail is demo.nutritionist@healthylife.test, the
 * patients' usernames start with "demo." and their phones are in the fictional +1 555-555-01xx
 * range. `--reset` deletes only accounts carrying the flag, and refuses (before deleting
 * anything) when the flag and the data disagree.
 *
 * History is written through the same services the API uses, with the clock moved to each
 * entry's own time (like the older version of this command), so meal types, late marks and
 * adherence come out as if the patients had logged live. Nothing here calls the AI, sends a
 * push or queues a job: demo accounts never get a device token, and NotificationService
 * skips the push for any demo account (the inbox row is still written).
 */
class SeedDemoData extends Command
{
    protected $signature = 'demo:seed {--reset : Delete the demo accounts (only those) and their data, then build them again}';

    protected $description = 'Create the fake demo nutritionist and four demo patients (idempotent; --reset rebuilds only the demo accounts)';

    public const NUTRITIONIST_EMAIL = 'demo.nutritionist@healthylife.test';

    /** Days of meal logs, ending yesterday. */
    private const LOG_DAYS = 14;

    /** Weight readings over six weeks, as days before today. */
    private const READING_DAYS = [42, 38, 35, 31, 28, 24, 21, 17, 14, 10, 7, 3, 1];

    /** About one planned item in five is not eaten: ~80% adherence. */
    private const SKIP_PERCENT = 20;

    /** meal => [[food seed_key, grams, alternative seed_key|null], ...], before scaling to the patient's needs. */
    private const PLAN = [
        'breakfast' => [['ful-medames', 200, 'hummus'], ['whole-wheat-bread-arabic-style', 60, 'arabic-pita-bread'], ['boiled-egg-arabic-breakfast', 100, 'labneh']],
        'lunch' => [['chicken-kabsa', 300, 'shish-tawook'], ['fattoush', 150, 'tabbouleh']],
        'snack' => [['plain-yogurt-arabic-style', 170, 'labneh'], ['dates', 30, null]],
        'dinner' => [['lentil-soup', 300, 'shakshuka'], ['arabic-pita-bread', 50, 'whole-wheat-bread-arabic-style']],
    ];

    private const MEAL_TIMES = ['breakfast' => '08:10', 'lunch' => '13:35', 'snack' => '17:05', 'dinner' => '20:20'];

    /** Off-plan meals, used on a few days: [seed_key, grams, time]. */
    private const OFF_PLAN = [['muhallabia', 120, '22:40'], ['falafel', 100, '16:30'], ['kunafa', 150, '21:30'], ['fried-kibbeh', 120, '15:45']];

    /** Days ago on which a patient eats something off-plan. */
    private const OFF_PLAN_DAYS = [12, 9, 6, 3, 1];

    /**
     * Invented patients. Phones are in the +1 555-555-01xx range reserved for fiction.
     * `shape` is the weight trend: kg per reading (negative loses), toward the goal.
     */
    private const PATIENTS = [
        'a' => ['name' => 'هدى الشريف', 'phone' => '+15555550111', 'username' => 'demo.huda', 'gender' => 'female', 'age' => 46, 'height_cm' => 160, 'weight_kg' => 72.0,
            'goal_type' => 'weight_loss', 'target_weight_kg' => 65.0, 'step' => -0.3, 'activity_level' => 'light',
            'conditions' => ['diabetes_type2'], 'allergies' => [['group' => 'tree_nuts', 'class' => 'confirmed_allergy', 'note' => 'حساسية من المكسرات']],
            'medications' => [['name' => 'ميتفورمين', 'dose' => '500 ملغ', 'schedule' => 'مرتين يوميًا مع الطعام']],
            'lab_notes' => 'HbA1c 7.0%.', 'nutritionist_notes' => 'توزيع الكربوهيدرات على الوجبات ومتابعة السكر.',
            'consent' => true, 'rich' => true, 'reason' => 'special_occasion', 'sleep' => [7.0, 'good', 'low', 'good']],
        'b' => ['name' => 'سامر النجار', 'phone' => '+15555550112', 'username' => 'demo.samer', 'gender' => 'male', 'age' => 44, 'height_cm' => 175, 'weight_kg' => 99.0,
            'goal_type' => 'weight_loss', 'target_weight_kg' => 88.0, 'step' => -0.5, 'activity_level' => 'light',
            'conditions' => ['hypertension'], 'allergies' => [],
            'medications' => [['name' => 'أملوديبين', 'dose' => '5 ملغ', 'schedule' => 'مرة يوميًا صباحًا']],
            'lab_notes' => 'الكوليسترول الكلي 218 mg/dL.', 'nutritionist_notes' => 'تقليل الملح والخبز الأبيض.',
            'consent' => true, 'rich' => true, 'reason' => 'craving', 'sleep' => [6.0, 'ok', 'medium', 'ok']],
        'c' => ['name' => 'لين العمري', 'phone' => '+15555550113', 'username' => 'demo.lin', 'gender' => 'female', 'age' => 22, 'height_cm' => 168, 'weight_kg' => 49.0,
            'goal_type' => 'weight_gain', 'target_weight_kg' => 56.0, 'step' => 0.25, 'activity_level' => 'moderate',
            'conditions' => [], 'allergies' => [], 'medications' => [],
            'lab_notes' => null, 'nutritionist_notes' => 'تحتاج خطة لزيادة الوزن بشكل صحي.',
            'consent' => true, 'rich' => true, 'reason' => 'food_unavailable', 'sleep' => [8.0, 'good', 'low', 'good']],
        // The store-review account: nothing accepted yet, so the reviewer meets the consent screen first.
        'd' => ['name' => 'ريما الحداد', 'phone' => '+15555550114', 'username' => 'demo.review', 'gender' => 'female', 'age' => 30, 'height_cm' => 165, 'weight_kg' => 66.0,
            'goal_type' => 'health_energy', 'target_weight_kg' => null, 'step' => -0.1, 'activity_level' => 'moderate',
            'conditions' => [], 'allergies' => [], 'medications' => [],
            'lab_notes' => null, 'nutritionist_notes' => null,
            'consent' => false, 'rich' => false, 'reason' => null, 'sleep' => null],
    ];

    /** @var array<string, Food> */
    private array $foods = [];

    private CarbonImmutable $today;

    /** @var array<string, string> */
    private array $secrets = [];

    public function handle(
        PatientEntryService $entries,
        MealPlanService $plans,
        ClientCodeAllocator $codes,
        ClientDeletionService $deletion,
        NutritionCalculatorService $nutrition,
        MealPlanCalculatorService $calculator,
        AdherenceService $adherence,
        AlertEvaluationService $alerts,
    ): int {
        // Only the e-mail is checked before anything else: a real account holding it is never touched.
        $existing = User::where('email', self::NUTRITIONIST_EMAIL)->first();

        if ($existing !== null && ! $existing->is_demo) {
            $this->error(self::NUTRITIONIST_EMAIL.' belongs to an account demo:seed did not create. Nothing was changed.');

            return self::FAILURE;
        }

        if (! Role::where('name', 'nutritionist')->exists() || ! Role::where('name', 'client')->exists()) {
            $this->error('Roles are missing. Run `php artisan db:seed --class=RolesAndPermissionsSeeder` first.');

            return self::FAILURE;
        }

        if (($missing = $this->loadFoods()) !== []) {
            $this->error(Food::query()->count() === 0
                ? 'The food database is empty. Run `php artisan db:seed --class=ArabicFoodSeeder` first.'
                : 'The curated Arabic dishes are missing ('.implode(', ', $missing).'). Run `php artisan db:seed --class=ArabicFoodSeeder` first.');

            return self::FAILURE;
        }

        if ($existing !== null && ! $this->option('reset')) {
            $this->info('The demo data already exists; nothing was created. Use --reset to delete and rebuild the demo accounts.');
            $this->line('Demo usernames: '.self::NUTRITIONIST_EMAIL.', '.implode(', ', array_column(self::PATIENTS, 'username')));

            return self::SUCCESS;
        }

        $this->secrets = [
            'nutritionist' => (string) (config('demo.nutritionist_password') ?: Str::password(14, symbols: false)),
            'patient' => (string) (config('demo.patient_password') ?: Str::password(14, symbols: false)),
        ];
        $generated = [
            'nutritionist' => ! filled(config('demo.nutritionist_password')),
            'patient' => ! filled(config('demo.patient_password')),
        ];

        $this->today = ClinicDay::today();
        $selfTrigger = config('scheduling.self_trigger');
        // Alerts are evaluated once, below, at the real time; not on every save while the clock is moved back.
        config(['scheduling.self_trigger' => false]);

        try {
            [$nutritionist, $patients] = DB::transaction(function () use ($entries, $plans, $codes, $deletion, $nutrition, $calculator) {
                if ($this->option('reset')) {
                    $this->removeDemoAccounts($deletion);
                }

                return $this->build($entries, $plans, $codes, $nutrition, $calculator);
            });
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            Carbon::setTestNow();
            config(['scheduling.self_trigger' => $selfTrigger]);
        }

        // What the 06:00 job does for these patients (adherence status, then alerts), at the real time.
        foreach ($patients as $subscriber) {
            if ($subscriber->fresh()->isActive()) {
                $adherence->refreshStatus($subscriber);
                $alerts->evaluate($subscriber);
            }
        }

        $this->report($nutritionist, $patients, $generated);

        return self::SUCCESS;
    }

    /** @return list<string> seed_keys the demo needs that are not approved foods */
    private function loadFoods(): array
    {
        $keys = collect(self::PLAN)->flatten(1)->flatMap(fn ($item) => [$item[0], $item[2]])
            ->merge(array_column(self::OFF_PLAN, 0))
            ->filter()->unique()->values();

        $this->foods = Food::query()->where('status', 'approved')->whereIn('seed_key', $keys)->get()->keyBy('seed_key')->all();

        return $keys->diff(array_keys($this->foods))->values()->all();
    }

    /**
     * Deletes the demo accounts and everything recorded about them, and nothing else.
     *
     * The rule is enforced here, before any delete: every account removed must carry the demo
     * flag, and a demo nutritionist must have no patient without it. Any exception to that stops the
     * whole reset with nothing deleted.
     */
    private function removeDemoAccounts(ClientDeletionService $deletion): void
    {
        $demo = User::query()->where('is_demo', true)->get();
        $demoIds = $demo->pluck('id')->all();

        foreach ($demo as $account) {
            $foreign = User::query()->where('nutritionist_id', $account->id)->whereNotIn('id', $demoIds)->exists();

            if ($foreign) {
                throw new RuntimeException('Reset refused: a demo account has a patient that is not a demo account. Nothing was deleted.');
            }
        }

        foreach ($demo as $account) {
            $this->assertDemo($account);
        }

        [$nutritionists, $patients] = $demo->partition(fn (User $u) => $u->hasRole('nutritionist'));

        foreach ($patients as $patient) {
            $this->assertDemo($patient);
            $subscriber = Subscriber::withoutGlobalScopes()->where('user_id', $patient->id)->first();
            $subscriber !== null ? $deletion->delete($subscriber) : $patient->delete();
        }

        foreach ($nutritionists as $nutritionist) {
            $this->assertDemo($nutritionist);
            $nutritionist->delete();
        }
    }

    /** The last check before a delete: the row in hand really carries the marker. */
    private function assertDemo(User $user): void
    {
        if (! User::query()->whereKey($user->id)->where('is_demo', true)->exists()) {
            throw new RuntimeException('Reset refused: an account without the demo marker was about to be deleted. Nothing was deleted.');
        }
    }

    /** @return array{0: User, 1: array<string, Subscriber>} */
    private function build(
        PatientEntryService $entries,
        MealPlanService $plans,
        ClientCodeAllocator $codes,
        NutritionCalculatorService $nutrition,
        MealPlanCalculatorService $calculator,
    ): array {
        $start = $this->today->subDays(44);
        $this->travel($start->setTime(10, 0));

        $nutritionist = User::create([
            'name' => 'د. منى القاسم (تجريبي)',
            'email' => self::NUTRITIONIST_EMAIL,
            'password' => Hash::make($this->secrets['nutritionist']),
        ]);
        $nutritionist->forceFill(['email_verified_at' => now(), 'is_demo' => true, 'country' => 'PS'])->save();
        $nutritionist->assignRole('nutritionist');
        $nutritionist->nutritionistProfile()->create([
            'specialty' => 'تغذية علاجية وإدارة الوزن',
            'clinic_name' => 'عيادة تجريبية',
            'gender' => 'female',
            'whatsapp_number' => '+15555550110',
            'reply_hours' => 'الأحد–الخميس 9–2',
            'bio' => 'حساب تجريبي لأغراض العرض والمراجعة. كل البيانات هنا وهمية.',
        ]);

        foreach (range(0, 4) as $weekday) {
            NutritionistAvailability::create(['nutritionist_id' => $nutritionist->id, 'weekday' => $weekday, 'start_time' => '09:00', 'end_time' => '14:00']);
        }

        $records = app(HealthRecordService::class);
        $patients = [];

        foreach (self::PATIENTS as $key => $spec) {
            $this->travel($start->addMinutes(5 * count($patients))->setTime(10, 30));

            $user = User::create([
                'name' => $spec['name'],
                'phone' => $spec['phone'],
                'username' => $spec['username'],
                'password' => Hash::make($this->secrets['patient']),
                'nutritionist_id' => $nutritionist->id,
            ]);
            $user->forceFill(['is_demo' => true])->save();
            $user->assignRole('client');

            $subscriber = Subscriber::create([
                'user_id' => $user->id,
                'nutritionist_id' => $nutritionist->id,
                'code' => $codes->next($nutritionist),
                'goal' => PatientGoal::LEGACY_GOAL[$spec['goal_type']],
                'status' => 'active',
            ]);
            $subscriber->forceFill(['activated_at' => now()])->save();

            $needs = $nutrition->calculateDailyCalorieNeeds(
                weightKg: (float) $spec['weight_kg'], heightCm: (float) $spec['height_cm'], age: (int) $spec['age'], gender: $spec['gender'], activityLevel: $spec['activity_level'],
            );
            $subscriber->healthProfile()->create([
                'weight_kg' => $spec['weight_kg'], 'height_cm' => $spec['height_cm'], 'age' => $spec['age'], 'gender' => $spec['gender'],
                'activity_level' => $spec['activity_level'], 'health_conditions' => [], 'medications' => [], 'allergies' => [],
                'food_preferences' => [], 'lab_notes' => $spec['lab_notes'], 'nutritionist_notes' => $spec['nutritionist_notes'],
                'daily_calorie_needs' => $needs,
                'bmr' => $nutrition->calculateBmr((float) $spec['weight_kg'], (float) $spec['height_cm'], (int) $spec['age'], $spec['gender']),
            ]);
            $records->setGoal($subscriber, ['goal_type' => $spec['goal_type'], 'target_weight_kg' => $spec['target_weight_kg']]);
            $records->syncLegacyArrays($subscriber, $spec['medications'], []);

            foreach ($spec['conditions'] as $code) {
                $records->addCondition($subscriber, ['code' => $code], $nutritionist);
            }
            foreach ($spec['allergies'] as $allergy) {
                $records->addAllergy($subscriber, $allergy);
            }

            if ($spec['consent']) {
                app(ConsentService::class)->accept($user, Request::create('/'));
            }

            $plan = $plans->create($subscriber, [
                'start_date' => $this->today->subDays(self::LOG_DAYS + 1)->toDateString(),
                'meals' => $this->planMeals($needs, $calculator),
            ], $nutritionist);
            $plans->activate($plan);

            $patients[$key] = $subscriber;
        }

        foreach ($patients as $key => $subscriber) {
            $spec = self::PATIENTS[$key];
            $this->writeWeights($subscriber, $spec, $entries);

            if ($spec['rich']) {
                $this->writeMealLogs($subscriber, $spec, array_search($key, array_keys(self::PATIENTS), true), $entries);
                $this->writeWellbeing($subscriber, $spec);
                $this->writeCheckins($subscriber);
            }
        }

        $this->travel($this->today->subDay()->setTime(21, 0));
        $this->writeFollowUp($nutritionist, $patients);

        return [$nutritionist, $patients];
    }

    /**
     * Portions scaled so the plan is about 85% of the patient's daily needs; each alternative is
     * sized to the same calories as the item it replaces.
     *
     * @return list<array<string, mixed>>
     */
    private function planMeals(int $dailyNeeds, MealPlanCalculatorService $calculator): array
    {
        $kcal = fn (string $key, float $grams) => $calculator->macrosFor($this->foods[$key], $grams)['calories'];
        $base = collect(self::PLAN)->flatten(1)->sum(fn ($item) => $kcal($item[0], $item[1]));
        $factor = 0.85 * $dailyNeeds / $base;
        $round = fn (float $grams) => max(10, (int) (round($grams / 10) * 10));

        $meals = [];
        foreach (array_keys(self::PLAN) as $sort => $name) {
            $items = [];
            foreach (self::PLAN[$name] as [$key, $grams, $altKey]) {
                $qty = $round($grams * $factor);
                $item = ['food_id' => $this->foods[$key]->id, 'quantity_grams' => $qty, 'alternatives' => []];

                if ($altKey !== null) {
                    $item['alternatives'][] = ['food_id' => $this->foods[$altKey]->id, 'quantity_grams' => $round($kcal($key, $qty) / $kcal($altKey, 100) * 100)];
                }

                $items[] = $item;
            }
            $meals[] = ['name' => $name, 'sort_order' => $sort, 'items' => $items];
        }

        return $meals;
    }

    /** Six weeks of weight readings moving toward the goal (patient d: three readings). @param  array<string, mixed>  $spec */
    private function writeWeights(Subscriber $subscriber, array $spec, PatientEntryService $entries): void
    {
        $days = $spec['rich'] ? self::READING_DAYS : [14, 7, 1];

        foreach ($days as $k => $daysAgo) {
            // A little deterministic wobble so the line looks like a person's, not a ruler.
            $weight = round($spec['weight_kg'] + $spec['step'] * $k + ((($k * 7) % 3) - 1) * 0.1, 1);
            $at = $this->today->subDays($daysAgo)->setTime(7, 30);
            $this->travel($at->addMinutes(2));
            $entries->recordNewReading($subscriber, $at, ['weight_kg' => $weight]);
        }
    }

    /**
     * 14 days of meal logs at about 80% adherence, plus a few off-plan meals.
     *
     * @param  array<string, mixed>  $spec
     */
    private function writeMealLogs(Subscriber $subscriber, array $spec, int $index, PatientEntryService $entries): void
    {
        $plan = MealPlan::query()->where('subscriber_id', $subscriber->id)->where('status', 'active')->with('meals.items.meal')->firstOrFail();
        $jitter = 3 * $index;

        for ($daysAgo = self::LOG_DAYS; $daysAgo >= 1; $daysAgo--) {
            $day = $this->today->subDays($daysAgo);

            foreach ($plan->meals->sortBy('sort_order') as $meal) {
                foreach ($meal->items->whereNull('parent_item_id')->sortBy('sort_order')->values() as $position => $item) {
                    if (crc32("{$index}:{$daysAgo}:{$meal->name}:{$position}") % 100 < self::SKIP_PERCENT) {
                        continue;
                    }

                    // Every fourth day the main lunch dish is swapped for its alternative.
                    $eaten = $meal->name === 'lunch' && $position === 0 && $daysAgo % 4 === 1
                        ? ($meal->items->firstWhere('parent_item_id', $item->id) ?? $item)
                        : $item;

                    $this->log($subscriber, $entries, $eaten, (int) $eaten->food_id, (float) $eaten->quantity_grams, $day, self::MEAL_TIMES[$meal->name], $jitter + $position * 4);
                }
            }

            if (in_array($daysAgo, self::OFF_PLAN_DAYS, true)) {
                [$foodKey, $grams, $time] = self::OFF_PLAN[($daysAgo + $index) % count(self::OFF_PLAN)];
                $this->log($subscriber, $entries, null, $this->foods[$foodKey]->id, $grams, $day, $time, $jitter);
            }
        }
    }

    private function log(Subscriber $subscriber, PatientEntryService $entries, ?MealItem $item, int $foodId, float $grams, CarbonImmutable $day, string $time, int $minutes): void
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        $at = $day->setTime($h, $m)->addMinutes($minutes);
        $this->travel($at->addMinutes(3));

        $entries->logMeal($subscriber, [
            'food_id' => $foodId,
            'meal_item_id' => $item?->id,
            'quantity_grams' => $grams,
            'idempotency_key' => (string) Str::uuid(),
        ], $at, $entries->mealType($item, null, $at));
    }

    /** Sleep and mood for the last seven days (the window the app allows). @param  array<string, mixed>  $spec */
    private function writeWellbeing(Subscriber $subscriber, array $spec): void
    {
        [$hours, $quality, $stress, $mood] = $spec['sleep'];
        $wellbeing = app(WellbeingService::class);

        for ($daysAgo = 7; $daysAgo >= 1; $daysAgo--) {
            $this->travel($this->today->subDays($daysAgo)->setTime(22, 0));
            $wobble = (($daysAgo * 5) % 4) * 0.5 - 0.5;

            $wellbeing->upsert($subscriber, $this->today->subDays($daysAgo)->toDateString(), [
                'sleep_hours' => max(4.0, $hours + $wobble),
                'sleep_quality' => $daysAgo % 3 === 0 ? 'ok' : $quality,
                'stress' => $daysAgo % 4 === 0 ? 'high' : $stress,
                'mood' => $daysAgo % 5 === 0 ? 'low' : $mood,
            ]);
        }
    }

    /** Two answered check-ins a week apart, through the real questionnaire validation. */
    private function writeCheckins(Subscriber $subscriber): void
    {
        $checkins = app(CheckinService::class);
        $answers = [
            ['adherence' => 'good', 'barriers' => ['time'], 'appetite' => 'normal', 'sleep' => 'good', 'note' => 'ملتزم بالخطة، وأحتاج أفكارًا لوجبات سريعة.'],
            ['adherence' => 'fair', 'barriers' => ['occasions', 'hunger'], 'appetite' => 'higher', 'sleep' => 'interrupted'],
        ];

        foreach ([14, 7] as $i => $daysAgo) {
            $this->travel($this->today->subDays($daysAgo)->setTime(18, 0));
            $checkin = PatientCheckin::create(['subscriber_id' => $subscriber->id, 'due_on' => $this->today->subDays($daysAgo)->toDateString(), 'status' => PatientCheckin::STATUS_PENDING]);
            $checkins->submit($checkin, $answers[$i]);
        }
    }

    /**
     * What the nutritionist's side shows: a measurement schedule, a quick request, an upcoming
     * appointment per patient, and one post to all of them.
     *
     * @param  array<string, Subscriber>  $patients
     */
    private function writeFollowUp(User $nutritionist, array $patients): void
    {
        Carbon::setTestNow();   // appointments, schedules and posts are about the real "now"

        $slots = collect(app(AppointmentService::class)->slots($nutritionist, 'follow_up'))->flatten()->values();
        $channels = ['whatsapp', 'phone'];   // video needs a meeting link the demo nutritionist does not have
        $slot = 0;

        foreach ($patients as $key => $subscriber) {
            $spec = self::PATIENTS[$key];

            if (! $spec['rich']) {
                continue;
            }

            app(MeasurementScheduleService::class)->set($subscriber, 14, $this->today->addDays(3 + $slot)->toDateString(), $nutritionist);

            app(QuickRequestService::class)->create($subscriber, [
                'reason' => $spec['reason'],
                'note' => 'طلب تجريبي للعرض.',
                'occasion_date' => $spec['reason'] === 'special_occasion' ? $this->today->addDays(5)->toDateString() : null,
            ]);

            if (isset($slots[$slot * 3 + 1])) {
                app(AppointmentService::class)->book($subscriber, [
                    'type' => 'follow_up', 'starts_at' => $slots[$slot * 3 + 1], 'channel' => $channels[$slot % 2], 'topics' => ['weight', 'meals'], 'note' => 'مراجعة الخطة.',
                ]);
            }

            $slot++;
        }

        $posts = app(PostService::class);
        $post = $posts->create($nutritionist, [
            'title' => 'نصيحة اليوم: ابدأ يومك بالماء',
            'body' => "شرب كوب ماء عند الاستيقاظ يساعد على الترطيب وعلى تنظيم الشهية.\nحاول أن توزّع شرب الماء على اليوم، وأن تحمل معك زجاجة صغيرة.",
            'audience' => 'all',
        ]);
        $posts->publish($post);
    }

    /** @param  array<string, Subscriber>  $patients @param  array<string, bool>  $generated */
    private function report(User $nutritionist, array $patients, array $generated): void
    {
        $this->newLine();
        $this->info('Demo data ready. Everything below is fake and flagged is_demo.');
        $this->line('Dashboard login:   '.$nutritionist->email.' / '.$this->secrets['nutritionist'].($generated['nutritionist'] ? '   (generated; shown only this once)' : ''));
        $this->line('Patient app login: username / '.$this->secrets['patient'].($generated['patient'] ? '   (generated; shown only this once)' : '').'  (same password for all four)');
        $this->newLine();

        $rows = [];
        foreach ($patients as $key => $subscriber) {
            $subscriber->refresh();
            $spec = self::PATIENTS[$key];
            $rows[] = [strtoupper($key), $subscriber->code, $spec['name'], $spec['username'], $spec['goal_type'], $spec['consent'] ? 'accepted' : 'NOT yet (consent screen first)', $subscriber->adherence_status ?? '-'];
        }
        $this->table(['#', 'Code', 'Name', 'Username', 'Goal', 'Consent', 'Adherence'], $rows);
        $this->line('No AI call was made and no push was sent. Passwords are not stored anywhere but hashed in the database.');
    }

    private function travel(CarbonImmutable $to): void
    {
        Carbon::setTestNow($to);
    }
}
