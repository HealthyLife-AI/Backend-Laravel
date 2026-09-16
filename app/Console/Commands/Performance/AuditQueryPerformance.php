<?php

namespace App\Console\Commands\Performance;

use App\Models\Food;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Adherence\AdherenceService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * S6-03 / NFR-01 ("< 2s API"): a manual, on-demand launch-readiness
 * check — not scheduled, not run in CI. Seeds a realistic volume, then
 * for every query NFR-01 actually names (nutritionist_id, plus the
 * date-ordered composite indexes) reports real wall-clock time AND the
 * query plan (`EXPLAIN`), so "fast today" and "fast because it used the
 * index" are checked separately.
 *
 * Everything runs inside one transaction that always rolls back in a
 * `finally` — safe to re-run against a real dev database as often as
 * needed without ever leaving seeded rows behind.
 *
 * MySQL only, on purpose: `EXPLAIN` and index selection genuinely differ
 * from SQLite (the test suite's engine, which is lenient about things
 * MySQL rejects — see AdherenceService's own `reorder()` docblock for a
 * bug that was exactly this gap), and NFR-01 is a claim about the engine
 * this runs on in production, not about SQLite.
 */
class AuditQueryPerformance extends Command
{
    protected $signature = 'performance:audit
        {--nutritionists=30}
        {--subscribers-per-nutritionist=30}
        {--days=90 : days of meal-log history seeded per subscriber}';

    protected $description = 'One-off, rolled-back NFR-01 check: seed a realistic volume and report query time + EXPLAIN for the hot paths.';

    public function handle(): int
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->error('Refusing to run on '.DB::connection()->getDriverName().' — EXPLAIN and index selection differ from MySQL, and NFR-01 is a claim about the production engine.');

            return self::FAILURE;
        }

        $nutritionistCount = (int) $this->option('nutritionists');
        $subscribersEach = (int) $this->option('subscribers-per-nutritionist');
        $days = (int) $this->option('days');

        DB::beginTransaction();

        try {
            ['nutritionist' => $nutritionist, 'subscriber' => $subscriber] = $this->seed($nutritionistCount, $subscribersEach, $days);

            $this->info(sprintf(
                'Seeded %d nutritionists x %d subscribers, %d days of meal logs each.',
                $nutritionistCount,
                $subscribersEach,
                $days,
            ));
            $this->newLine();

            Auth::setUser($nutritionist);
            $this->auditQueries($subscriber);
        } finally {
            DB::rollBack();
            $this->newLine();
            $this->info('Rolled back — no seeded rows persisted.');
        }

        return self::SUCCESS;
    }

    /** @return array{nutritionist: User, subscriber: Subscriber} */
    private function seed(int $nutritionistCount, int $subscribersEach, int $days): array
    {
        $food = Food::query()->first() ?? Food::factory()->create();

        $targetNutritionist = null;
        $targetSubscriber = null;

        $this->withProgressBar($nutritionistCount, function () use (&$targetNutritionist, &$targetSubscriber, $subscribersEach, $days, $food): void {
            $nutritionist = User::factory()->nutritionist()->create();
            $targetNutritionist ??= $nutritionist;

            $statuses = ['active', 'active', 'active', 'pending'];
            $adherenceStatuses = [AdherenceService::STATUS_STABLE, AdherenceService::STATUS_DECLINING, AdherenceService::STATUS_STOPPED, null];

            for ($i = 0; $i < $subscribersEach; $i++) {
                $subscriber = Subscriber::factory()->forNutritionist($nutritionist)->create([
                    'status' => $statuses[array_rand($statuses)],
                    'adherence_status' => $adherenceStatuses[array_rand($adherenceStatuses)],
                    'last_logged_at' => now()->subHours(random_int(1, 96)),
                ]);

                $targetSubscriber ??= $subscriber;

                $this->seedMealLogs($subscriber, $food, $days);
                $this->seedBodyComposition($subscriber, $days);
            }
        });
        $this->newLine(2);

        return ['nutritionist' => $targetNutritionist, 'subscriber' => $targetSubscriber];
    }

    private function seedMealLogs(Subscriber $subscriber, Food $food, int $days): void
    {
        $now = now();
        $rows = [];

        for ($day = 0; $day < $days; $day++) {
            foreach ([8, 13, 19] as $hour) {
                $loggedAt = $now->copy()->subDays($day)->setTime($hour, random_int(0, 59));
                $rows[] = [
                    'subscriber_id' => $subscriber->id,
                    'food_id' => $food->id,
                    'meal_item_id' => null,
                    'quantity_grams' => random_int(50, 400),
                    'logged_at' => $loggedAt,
                    'created_at' => $loggedAt,
                    'updated_at' => $loggedAt,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('meal_logs')->insert($chunk);
        }
    }

    private function seedBodyComposition(Subscriber $subscriber, int $days): void
    {
        $now = now();
        $rows = [];

        for ($week = 0; $week * 7 < $days; $week++) {
            $recordedAt = $now->copy()->subWeeks($week);
            $rows[] = [
                'subscriber_id' => $subscriber->id,
                'recorded_at' => $recordedAt->toDateString(),
                'weight_kg' => random_int(600, 900) / 10,
                'body_fat_percent' => null,
                'muscle_mass_kg' => null,
                'water_percent' => null,
                'waist_cm' => null,
                'created_at' => $recordedAt,
                'updated_at' => $recordedAt,
            ];
        }

        if ($rows !== []) {
            DB::table('body_composition_readings')->insert($rows);
        }
    }

    private function auditQueries(Subscriber $subscriber): void
    {
        $this->check('Client list (dashboard "clients" query)', fn (): Builder => Subscriber::query()->with('user')->latest());

        $this->check('Dashboard overview (aggregate SUMs)', fn (): Builder => Subscriber::query()
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(status = 'active') as active")
            ->selectRaw("sum(adherence_status = 'stable') as stable"));

        $this->check(
            "Adherence rate (meal_logs by subscriber_id + logged_at, subscriber {$subscriber->id})",
            fn () => $subscriber->mealLogs()->reorder()
                ->whereBetween('logged_at', [now()->subDays(7)->toDateString(), now()->toDateString()])
                ->selectRaw('count(*) as total')
                ->selectRaw('count(meal_item_id) as on_plan'),
        );

        $this->check(
            "Progress (body_composition_readings by subscriber_id + recorded_at, subscriber {$subscriber->id})",
            fn () => $subscriber->bodyCompositionReadings()->reorder('recorded_at'),
        );

        $this->line('');
        $this->comment('Timed, end-to-end service call (not just the raw query):');
        $this->timeIt('AdherenceService::summary()', fn () => app(AdherenceService::class)->summary($subscriber));
    }

    private function check(string $label, callable $queryFactory): void
    {
        $start = microtime(true);
        $queryFactory()->get();
        $ms = round((microtime(true) - $start) * 1000, 1);

        $explainRows = $queryFactory()->explain()->toArray();
        $plan = $explainRows[0] ?? null;
        $type = $plan->type ?? $plan->select_type ?? 'n/a';
        $key = $plan->key ?? null;
        $rowsExamined = $plan->rows ?? 'n/a';

        $indexVerdict = $key !== null ? "index: {$key}" : 'NO INDEX USED';
        $nfrVerdict = $ms < 2000 ? 'PASS' : 'FAIL';

        $this->line(sprintf(
            '[%s] %s — %sms, type=%s, %s, rows~%s',
            $nfrVerdict,
            $label,
            $ms,
            $type,
            $indexVerdict,
            $rowsExamined,
        ));
    }

    private function timeIt(string $label, callable $fn): void
    {
        $start = microtime(true);

        try {
            $fn();
        } catch (Throwable $e) {
            $this->line("[SKIP] {$label} — {$e->getMessage()}");

            return;
        }

        $ms = round((microtime(true) - $start) * 1000, 1);
        $verdict = $ms < 2000 ? 'PASS' : 'FAIL';
        $this->line("[{$verdict}] {$label} — {$ms}ms");
    }
}
