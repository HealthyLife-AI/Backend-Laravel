<?php

namespace App\Console\Commands\Demo;

use App\Models\Alert;
use App\Models\Food;
use App\Models\MealItem;
use App\Models\MealPlan;
use App\Models\NutritionistAvailability;
use App\Models\PatientGoal;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Adherence\AdherenceService;
use App\Services\AiSummaries\WeeklySummaryService;
use App\Services\Alerts\AlertEvaluationService;
use App\Services\Appointments\AppointmentService;
use App\Services\Clients\ClientCodeAllocator;
use App\Services\Clients\ClientDeletionService;
use App\Services\FollowUp\ReviewService;
use App\Services\HealthRecords\HealthRecordService;
use App\Services\Logs\PatientEntryService;
use App\Services\MealPlans\MealPlanService;
use App\Services\Nutrition\MealPlanCalculatorService;
use App\Services\Nutrition\NutritionCalculatorService;
use App\Support\ClinicDay;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Demo data for the product video and the App Store review account: one
 * nutritionist and seven patients with 21 days of history ending yesterday.
 *
 * History is written through the same services the API uses
 * (PatientEntryService, MealPlanService, ClientCodeAllocator), with the clock
 * moved to each entry's own time, so meal_type, is_late and adherence come
 * out exactly as if the patients had logged live. Then the scheduler's own
 * services run for these patients only (never for anyone else's).
 *
 * Idempotent: a re-run deletes the demo nutritionist and their patients
 * (found by the demo e-mail) and builds them again; no other row is touched.
 */
class SeedDemoData extends Command
{
    protected $signature = 'demo:seed {--force : Required on any APP_ENV other than local or testing (production included)}';

    protected $description = 'Create (or recreate) the demo nutritionist and seven demo patients with 21 days of history';

    public const NUTRITIONIST_EMAIL = 'demo.nutritionist@healthylife.test';

    /** Days of history, ending yesterday. */
    private const DAYS = 21;

    /** Reading days, as days before today: every 3-4 days. */
    private const READING_DAYS = [21, 18, 14, 11, 7, 4, 1];

    /**
     * The plan every patient with a plan follows, before portions are scaled
     * to their own calorie needs: meal => [food seed_key, grams, alternative seed_key|null].
     */
    private const PLAN = [
        'breakfast' => [['ful-medames', 200, 'hummus'], ['whole-wheat-bread-arabic-style', 60, 'arabic-pita-bread'], ['boiled-egg-arabic-breakfast', 100, 'labneh']],
        'lunch' => [['chicken-kabsa', 300, 'shish-tawook'], ['fattoush', 150, 'tabbouleh']],
        'snack' => [['plain-yogurt-arabic-style', 170, 'labneh'], ['dates', 30, null]],
        'dinner' => [['lentil-soup', 300, 'shakshuka'], ['arabic-pita-bread', 50, 'whole-wheat-bread-arabic-style']],
    ];

    private const MEAL_TIMES = ['breakfast' => '08:10', 'lunch' => '13:35', 'snack' => '17:05', 'dinner' => '20:20'];

    /** Off-plan extras: [seed_key, grams, time]. */
    private const LIGHT_EXTRA = ['muhallabia', 120, '23:10'];

    private const SLIPPING_EXTRAS = [['fried-kibbeh', 150, '16:30'], ['falafel', 100, '22:15']];

    private const HEAVY_EXTRAS = [['kunafa', 300, '21:45'], ['beef-shawarma', 300, '23:20']];

    /**
     * Fictional patients. Phones are in the +1 555-555-01xx range reserved
     * for fiction, so they never collide with a real number.
     */
    private const PATIENTS = [
        ['pattern' => 'stable', 'name' => 'سارة الخطيب', 'phone' => '+15555550101', 'username' => 'demo.sara', 'gender' => 'female', 'goal' => 'weight_maintenance',
            'age' => 34, 'height_cm' => 163, 'weight_kg' => 64.0, 'weight_step' => 0.1, 'activity_level' => 'moderate',
            'health_conditions' => [], 'medications' => [], 'allergies' => ['المكسرات'], 'food_preferences' => ['تفضّل الأطباق المنزلية'],
            'surgery_history' => null, 'lab_notes' => 'فيتامين د 28 ng/mL (أقل من المثالي).', 'nutritionist_notes' => 'متابعة للحفاظ على الوزن بعد برنامج سابق.'],
        ['pattern' => 'stable', 'name' => 'محمد العلي', 'phone' => '+15555550102', 'username' => 'demo.mohammad', 'gender' => 'male', 'goal' => 'weight_loss',
            'age' => 41, 'height_cm' => 176, 'weight_kg' => 96.0, 'weight_step' => -0.2, 'activity_level' => 'light',
            'health_conditions' => ['ارتفاع ضغط الدم'], 'medications' => [['name' => 'أملوديبين', 'dose' => '5 ملغ', 'schedule' => 'مرة يوميًا صباحًا']],
            'allergies' => [], 'food_preferences' => ['لا يحب السمك'],
            'surgery_history' => null, 'lab_notes' => 'الكوليسترول الكلي 215 mg/dL.', 'nutritionist_notes' => 'تقليل الملح والخبز الأبيض.'],
        ['pattern' => 'stable', 'name' => 'نور الهدى سليمان', 'phone' => '+15555550103', 'username' => 'demo.nour', 'gender' => 'female', 'goal' => 'health_monitoring',
            'age' => 52, 'height_cm' => 158, 'weight_kg' => 70.0, 'weight_step' => 0.0, 'activity_level' => 'light',
            'health_conditions' => ['سكري من النوع الثاني'], 'medications' => [['name' => 'ميتفورمين', 'dose' => '500 ملغ', 'schedule' => 'مرتين يوميًا مع الطعام']],
            'allergies' => [], 'food_preferences' => ['نباتية في أيام الصيام'],
            'surgery_history' => 'استئصال المرارة (2019).', 'lab_notes' => 'HbA1c 7.1%.', 'nutritionist_notes' => 'توزيع الكربوهيدرات على الوجبات.'],
        ['pattern' => 'declining', 'name' => 'خالد منصور', 'phone' => '+15555550104', 'username' => 'demo.khaled', 'gender' => 'male', 'goal' => 'weight_loss',
            'age' => 38, 'height_cm' => 180, 'weight_kg' => 104.0, 'weight_step' => -0.15, 'activity_level' => 'light',
            'health_conditions' => ['مقاومة الإنسولين'], 'medications' => [],
            'allergies' => [], 'food_preferences' => ['يحب الحلويات الشرقية', 'يأكل خارج المنزل في نهاية الأسبوع'],
            'surgery_history' => null, 'lab_notes' => 'سكر صائم 108 mg/dL.', 'nutritionist_notes' => 'بدأ بحماس، يحتاج متابعة أسبوعية.'],
        ['pattern' => 'stopped', 'name' => 'ريم عبد الله', 'phone' => '+15555550105', 'username' => 'demo.reem', 'gender' => 'female', 'goal' => 'weight_loss',
            'age' => 27, 'height_cm' => 165, 'weight_kg' => 78.0, 'weight_step' => -0.2, 'activity_level' => 'moderate',
            'health_conditions' => [], 'medications' => [],
            'allergies' => ['الفراولة'], 'food_preferences' => ['تفضّل الوجبات السريعة التحضير'],
            'surgery_history' => null, 'lab_notes' => 'مخزون الحديد (فيريتين) 15 ng/mL.', 'nutritionist_notes' => 'دوام عمل طويل، الوجبات غير منتظمة.'],
        ['pattern' => 'milestone', 'name' => 'يوسف الحسيني', 'phone' => '+15555550106', 'username' => 'demo.yousef', 'gender' => 'male', 'goal' => 'weight_loss',
            'age' => 45, 'height_cm' => 172, 'weight_kg' => 92.0, 'weight_step' => -0.6, 'activity_level' => 'moderate',
            'health_conditions' => ['دهون على الكبد'], 'medications' => [],
            'allergies' => [], 'food_preferences' => ['يمشي 30 دقيقة يوميًا'],
            'surgery_history' => null, 'lab_notes' => 'ALT 52 U/L.', 'nutritionist_notes' => 'ملتزم جدًا، نزول ثابت في الوزن.'],
        ['pattern' => 'new', 'name' => 'هبة ناصر', 'phone' => '+15555550107', 'username' => 'demo.heba', 'gender' => 'female', 'goal' => 'weight_gain',
            'age' => 23, 'height_cm' => 167, 'weight_kg' => 49.0, 'weight_step' => 0.0, 'activity_level' => 'moderate',
            'health_conditions' => ['فقر دم'], 'medications' => [['name' => 'حديد', 'dose' => '65 ملغ', 'schedule' => 'مرة يوميًا']],
            'allergies' => ['اللاكتوز'], 'food_preferences' => ['تحب الأرز والدجاج'],
            'surgery_history' => null, 'lab_notes' => 'هيموجلوبين 10.9 g/dL.', 'nutritionist_notes' => 'مراجعة أولى: تحتاج خطة لزيادة الوزن.'],
    ];

    /** @var array<string, Food> */
    private array $foods = [];

    private CarbonImmutable $today;

    public function handle(
        PatientEntryService $entries,
        MealPlanService $plans,
        ClientCodeAllocator $codes,
        ClientDeletionService $deletion,
        NutritionCalculatorService $nutrition,
        MealPlanCalculatorService $calculator,
        AdherenceService $adherence,
        AlertEvaluationService $alerts,
        WeeklySummaryService $summaries,
    ): int {
        // Only a developer machine runs it unprompted; production, staging or an
        // unset APP_ENV (Laravel then assumes production) need --force.
        if (! app()->environment(['local', 'testing']) && ! $this->option('force')) {
            $this->error('Refusing to seed demo data with APP_ENV='.app()->environment().'. Pass --force if you really mean to.');

            return self::FAILURE;
        }

        $password = config('demo.password');
        if (! filled($password)) {
            $this->error('DEMO_PASSWORD is not set. Add DEMO_PASSWORD=<a password> to .env (then `php artisan config:clear` if config is cached).');

            return self::FAILURE;
        }

        // Only an account this command created (is_demo) is ever deleted: a
        // real user who registered with this e-mail is left alone.
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

        $this->today = ClinicDay::today();
        $selfTrigger = config('scheduling.self_trigger');
        // Alerts are evaluated once, below, at the real time; not on every
        // save while the clock is moved back.
        config(['scheduling.self_trigger' => false]);

        try {
            [$nutritionist, $patients] = DB::transaction(function () use ($password, $entries, $plans, $codes, $deletion, $nutrition, $calculator) {
                $this->removePrevious($deletion);

                return $this->build($password, $entries, $plans, $codes, $nutrition, $calculator);
            });
        } finally {
            Carbon::setTestNow();
            config(['scheduling.self_trigger' => $selfTrigger]);
        }

        // What the scheduler runs, in its order, for the demo patients only:
        // the 06:00 job (adherence recompute, then alerts), then the weekly summary.
        $active = collect($patients)->filter(fn (Subscriber $s) => $s->fresh()->isActive());
        foreach ($active as $subscriber) {
            $adherence->refreshStatus($subscriber);
            $alerts->evaluate($subscriber);
        }

        $weekStart = CarbonImmutable::now()->subWeek()->startOfWeek();
        $fallbacks = 0;
        foreach ($active as $subscriber) {
            if ($summaries->generateForWeek($subscriber, $weekStart)->is_fallback) {
                $fallbacks++;
            }
        }

        $this->seedFollowUp($nutritionist, $patients);

        $this->report($nutritionist, $patients, $active->count(), $fallbacks, $weekStart);

        return self::SUCCESS;
    }

    /** @return list<string> seed_keys the demo needs that are not approved foods */
    private function loadFoods(): array
    {
        $keys = collect(self::PLAN)->flatten(1)->flatMap(fn ($item) => [$item[0], $item[2]])
            ->merge([self::LIGHT_EXTRA[0]])
            ->merge(array_column(self::SLIPPING_EXTRAS, 0))
            ->merge(array_column(self::HEAVY_EXTRAS, 0))
            ->filter()->unique()->values();

        $this->foods = Food::query()->where('status', 'approved')->whereIn('seed_key', $keys)->get()->keyBy('seed_key')->all();

        return $keys->diff(array_keys($this->foods))->values()->all();
    }

    private function removePrevious(ClientDeletionService $deletion): void
    {
        $old = User::where('email', self::NUTRITIONIST_EMAIL)->where('is_demo', true)->first();

        if ($old === null) {
            return;
        }

        User::where('nutritionist_id', $old->id)->get()->each(function (User $patient) use ($deletion) {
            $subscriber = Subscriber::withoutGlobalScopes()->where('user_id', $patient->id)->first();
            $subscriber !== null ? $deletion->delete($subscriber) : $patient->delete();
        });

        $old->delete();
    }

    /** @return array{0: User, 1: list<Subscriber>} */
    private function build(
        string $password,
        PatientEntryService $entries,
        MealPlanService $plans,
        ClientCodeAllocator $codes,
        NutritionCalculatorService $nutrition,
        MealPlanCalculatorService $calculator,
    ): array {
        $firstDay = $this->today->subDays(self::DAYS);
        $this->travel($firstDay->subDays(2)->setTime(10, 0));

        $nutritionist = User::create([
            'name' => 'أ. ليلى حسن',
            'email' => self::NUTRITIONIST_EMAIL,
            'password' => Hash::make($password),
        ]);
        $nutritionist->forceFill(['email_verified_at' => now(), 'is_demo' => true])->save();
        $nutritionist->assignRole('nutritionist');
        $nutritionist->nutritionistProfile()->create([
            'specialty' => 'تغذية علاجية وإدارة الوزن',
            'clinic_name' => 'عيادة الحياة الصحية',
            'gender' => 'female',
            'whatsapp_number' => '+15555550100',
            'bio' => 'أخصائية تغذية علاجية، أتابع مرضاي يوميًا عبر التطبيق.',
        ]);

        $patients = [];

        foreach (self::PATIENTS as $index => $spec) {
            $isNew = $spec['pattern'] === 'new';
            // The new patient was added yesterday; the rest three weeks ago.
            $this->travel($isNew ? $this->today->subDay()->setTime(11, 30) : $firstDay->subDay()->setTime(9, 0)->addMinutes(20 * $index));

            $user = User::create([
                'name' => $spec['name'],
                'phone' => $spec['phone'],
                // Prefixed "demo." so a real patient's username is never taken by the demo.
                'username' => $spec['username'],
                // The video follows patient 4 in the app; the others can't sign in.
                'password' => Hash::make($spec['pattern'] === 'declining' ? $password : Str::random(40)),
                'nutritionist_id' => $nutritionist->id,
            ]);
            $user->forceFill(['is_demo' => true])->save();
            $user->assignRole('client');

            $subscriber = Subscriber::create([
                'user_id' => $user->id,
                'nutritionist_id' => $nutritionist->id,
                'code' => $codes->next($nutritionist),
                'goal' => $spec['goal'],
                'status' => $isNew ? 'pending' : 'active',
            ]);
            // "pending" = hasn't signed in yet; the others signed in the day they were added.
            if (! $isNew) {
                $subscriber->forceFill(['activated_at' => now()])->save();
            }

            $profile = collect($spec)->only(['weight_kg', 'height_cm', 'age', 'gender', 'activity_level', 'health_conditions',
                'medications', 'allergies', 'food_preferences', 'surgery_history', 'lab_notes', 'nutritionist_notes'])->all();
            $profile['daily_calorie_needs'] = $nutrition->calculateDailyCalorieNeeds(
                weightKg: (float) $spec['weight_kg'],
                heightCm: (float) $spec['height_cm'],
                age: (int) $spec['age'],
                gender: $spec['gender'],
                activityLevel: $spec['activity_level'],
            );
            $subscriber->healthProfile()->create($profile);
            PatientGoal::create([
                'subscriber_id' => $subscriber->id,
                'goal_type' => $spec['goal_type'] ?? ['weight_loss' => 'weight_loss', 'weight_gain' => 'weight_gain', 'weight_maintenance' => 'weight_maintenance', 'health_monitoring' => 'health_energy'][$spec['goal']],
                'target_weight_kg' => $spec['goal'] === 'weight_loss' ? round($spec['weight_kg'] * 0.9, 1) : null,
            ]);
            app(HealthRecordService::class)->syncLegacyArrays($subscriber, $spec['medications'], $spec['allergies']);

            if (! $isNew) {
                $plan = $plans->create($subscriber, [
                    'start_date' => $firstDay->toDateString(),
                    'meals' => $this->planMeals($profile['daily_calorie_needs'], $calculator),
                ], $nutritionist);
                $plans->activate($plan);
            }

            $patients[] = $subscriber;
        }

        foreach ($patients as $index => $subscriber) {
            $spec = self::PATIENTS[$index];
            if ($spec['pattern'] !== 'new') {
                $this->writeHistory($subscriber, $spec, $index, $entries);
            }
        }

        return [$nutritionist, $patients];
    }

    /**
     * Portions scaled so the plan is about 85% of the patient's daily needs;
     * each alternative is sized to the same calories as the item it replaces.
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
                    $altPer100 = $kcal($altKey, 100);
                    $item['alternatives'][] = [
                        'food_id' => $this->foods[$altKey]->id,
                        'quantity_grams' => $round($kcal($key, $qty) / $altPer100 * 100),
                    ];
                }

                $items[] = $item;
            }
            $meals[] = ['name' => $name, 'sort_order' => $sort, 'items' => $items];
        }

        return $meals;
    }

    /** @param  array<string, mixed>  $spec */
    private function writeHistory(Subscriber $subscriber, array $spec, int $index, PatientEntryService $entries): void
    {
        $plan = MealPlan::query()->where('subscriber_id', $subscriber->id)->where('status', 'active')
            ->with('meals.items.meal')->firstOrFail();
        $jitter = 3 * $index;
        $weights = $this->weights($spec);

        for ($daysAgo = self::DAYS; $daysAgo >= 1; $daysAgo--) {
            $day = $this->today->subDays($daysAgo);

            if (isset($weights[$daysAgo])) {
                $at = $day->setTime(7, 30)->addMinutes($jitter);
                $this->travel($at->addMinutes(2));
                $entries->recordNewReading($subscriber, $at, ['weight_kg' => $weights[$daysAgo]]);
            }

            if ($spec['pattern'] === 'stopped' && $daysAgo < 5) {
                continue;
            }

            // Patient 4 slips in the last 6 days: skips the snack and the
            // dinner bread and eats off-plan instead; heavily so in the last 3.
            $slipping = $spec['pattern'] === 'declining' && $daysAgo <= 6;

            foreach ($plan->meals->sortBy('sort_order') as $meal) {
                foreach ($meal->items->whereNull('parent_item_id')->sortBy('sort_order')->values() as $position => $item) {
                    if ($slipping && ($meal->name === 'snack' || ($meal->name === 'dinner' && $position > 0))) {
                        continue;
                    }

                    // Every fourth day the main lunch dish is swapped for its alternative.
                    $eaten = $meal->name === 'lunch' && $position === 0 && $daysAgo % 4 === 1
                        ? ($meal->items->firstWhere('parent_item_id', $item->id) ?? $item)
                        : $item;

                    $this->log($subscriber, $entries, $eaten, (int) $eaten->food_id, (float) $eaten->quantity_grams,
                        $day, self::MEAL_TIMES[$meal->name], $jitter + $position * 4);
                }
            }

            $extras = match (true) {
                $slipping && $daysAgo <= 3 => self::HEAVY_EXTRAS,
                $slipping => self::SLIPPING_EXTRAS,
                $spec['pattern'] !== 'declining' && $daysAgo % 2 === 1 => [self::LIGHT_EXTRA],
                default => [],
            };

            foreach ($extras as [$key, $grams, $time]) {
                $this->log($subscriber, $entries, null, $this->foods[$key]->id, $grams, $day, $time, $jitter);
            }
        }
    }

    private function log(Subscriber $subscriber, PatientEntryService $entries, ?MealItem $item, int $foodId, float $grams,
        CarbonImmutable $day, string $time, int $minutes): void
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

    /**
     * One weight per reading day, moving by `weight_step` each time.
     *
     * @param  array<string, mixed>  $spec
     * @return array<int, float> days ago => kg
     */
    private function weights(array $spec): array
    {
        $weights = [];
        foreach (self::READING_DAYS as $k => $daysAgo) {
            if ($spec['pattern'] === 'stopped' && $daysAgo < 5) {
                continue;
            }
            $step = $spec['goal'] === 'weight_maintenance' ? $spec['weight_step'] * ($k % 2 ? 1 : -1) : $spec['weight_step'] * $k;
            $weights[$daysAgo] = round($spec['weight_kg'] + $step, 1);
        }

        return $weights;
    }

    private function travel(CarbonImmutable $to): void
    {
        Carbon::setTestNow($to);
    }

    /** @param  list<Subscriber>  $patients */
    private function report(User $nutritionist, array $patients, int $summarised, int $fallbacks, CarbonImmutable $weekStart): void
    {
        $this->newLine();
        $this->info('Demo data ready.');
        $this->line('Dashboard login:   '.self::NUTRITIONIST_EMAIL.' / password = DEMO_PASSWORD');
        $declining = collect($patients)->search(fn (Subscriber $s, int $i) => self::PATIENTS[$i]['pattern'] === 'declining');
        $this->line('Patient app login: username '.self::PATIENTS[$declining]['username'].' (or phone '.self::PATIENTS[$declining]['phone'].') / password = DEMO_PASSWORD ('.self::PATIENTS[$declining]['name'].')');
        $this->newLine();

        $rows = [];
        foreach ($patients as $i => $subscriber) {
            $subscriber->refresh();
            $open = $subscriber->alerts()->reorder('id')->get()->map(fn (Alert $a) => $a->type.($a->resolved_at ? ' (resolved)' : ''))->implode(', ');
            $rows[] = [$i + 1, $subscriber->code, self::PATIENTS[$i]['name'], self::PATIENTS[$i]['pattern'],
                $subscriber->status, $subscriber->adherence_status ?? '-', $open ?: '-'];
        }
        $this->table(['#', 'Code', 'Name', 'Pattern', 'Account', 'Adherence', 'Alerts'], $rows);

        $this->line(sprintf('Weekly summaries (week of %s): %d written, %d via fallback.', $weekStart->toDateString(), $summarised, $fallbacks));
    }

    /**
     * What the demo (and an app reviewer) needs to see the follow-up
     * features: availability and an upcoming appointment, reviews with tasks
     * (one acknowledged), notifications, and a pending allergy proposal for
     * the patient the video follows.
     *
     * @param  list<Subscriber>  $patients
     */
    private function seedFollowUp(User $nutritionist, array $patients): void
    {
        $declining = $patients[array_search('declining', array_column(self::PATIENTS, 'pattern'), true)];
        $stable = $patients[0];

        foreach (range(0, 4) as $weekday) {
            NutritionistAvailability::create(['nutritionist_id' => $nutritionist->id, 'weekday' => $weekday, 'start_time' => '09:00', 'end_time' => '14:00']);
        }
        $nutritionist->nutritionistProfile()->update(['reply_hours' => 'الأحد–الخميس 9–5']);

        $appointments = app(AppointmentService::class);
        foreach ($appointments->slots($nutritionist, 'follow_up') as $times) {
            $appointments->book($declining, ['type' => 'follow_up', 'starts_at' => $times[0], 'channel' => 'whatsapp', 'topics' => ['weight', 'meals'], 'note' => 'أريد مراجعة وجبات العشاء.']);
            break;
        }

        $reviews = app(ReviewService::class);
        $reviews->create($declining, $nutritionist, [
            'rating' => 'review_together',
            'note' => 'لاحظت ارتفاع السعرات في آخر ثلاثة أيام، خصوصًا الحلويات المسائية. لنراجع وجبة العشاء معًا في الموعد القادم.',
            'key_points' => ['استبدل الحلويات المسائية بفاكهة أو لبن', 'التزم بوجبة العشاء من الخطة'],
            'tasks' => [['title' => 'سجّل كل الوجبات لمدة 3 أيام'], ['title' => 'مشي 20 دقيقة بعد العشاء']],
        ]);
        $done = $reviews->create($stable, $nutritionist, [
            'rating' => 'on_track',
            'note' => 'أداء ممتاز هذا الأسبوع، استمري على نفس النمط.',
            'tasks' => [['title' => 'لترين ماء يوميًا']],
        ]);
        $done->forceFill(['acknowledged_at' => now()->subHours(3)])->save();
        $done->tasks()->first()?->forceFill(['done_at' => now()->subHours(2)])->save();

        app(HealthRecordService::class)->propose($declining, 'allergy', 'add', null, ['group' => 'sesame', 'class' => 'intolerance', 'note' => 'انتفاخ بعد الحمص والطحينة']);
    }
}
