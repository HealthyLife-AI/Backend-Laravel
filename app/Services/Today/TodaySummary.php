<?php

namespace App\Services\Today;

use App\Models\Appointment;
use App\Models\MealPlan;
use App\Models\PatientCheckin;
use App\Models\PatientQuickRequest;
use App\Models\PatientWellbeingLog;
use App\Models\Subscriber;
use App\Models\SubscriberMeasurementSchedule;
use App\Services\Posts\PostService;
use App\Services\Wellbeing\WellbeingService;
use App\Support\ArabicCount;
use App\Support\ClinicDay;
use Carbon\CarbonImmutable;

/**
 * Phase 3, B: "what is my day" for one patient, built ONLY from data the app
 * already holds. No LLM, no calorie or other number from the health file: the
 * meals are names and a part of the day, the reminders are fixed sentences.
 *
 * The motivation line is picked by (subscriber id + day of the year), so it
 * is the same all day for one patient and different between patients.
 */
class TodaySummary
{
    /** Part of the day for each meal name; the plan stores no clock times. */
    private const MEAL_WINDOWS = [
        'breakfast' => 'الصبح',
        'snack' => 'بين الوجبات',
        'lunch' => 'الظهر',
        'dinner' => 'المساء',
    ];

    private const MEAL_NAMES_AR = [
        'breakfast' => 'الفطور',
        'snack' => 'سناك',
        'lunch' => 'الغداء',
        'dinner' => 'العشاء',
    ];

    public function __construct(private readonly PostService $posts, private readonly WellbeingService $wellbeing) {}

    /**
     * @return array{date: string, meals: list<array{name: string, name_ar: string, window: string}>, activities: list<array{title: string, duration_minutes: int, times_per_day: int}>, reminders: list<array{type: string, text_ar: string}>, motivation_ar?: string}
     */
    public function for(Subscriber $subscriber, ?CarbonImmutable $date = null): array
    {
        $date ??= ClinicDay::today();
        $plan = $this->activePlan($subscriber, $date);

        $summary = [
            'date' => $date->toDateString(),
            'meals' => $plan === null ? [] : $this->meals($plan, $date),
            'activities' => $plan === null ? [] : $this->activities($plan),
            'reminders' => $this->reminders($subscriber, $date),
        ];

        $motivation = $this->motivation($subscriber, $date);
        if ($motivation !== null) {
            $summary['motivation_ar'] = $motivation;
        }

        return $summary;
    }

    /** The patient's active plan, once it has taken effect (not one that starts later). */
    public function activePlan(Subscriber $subscriber, ?CarbonImmutable $date = null): ?MealPlan
    {
        $date ??= ClinicDay::today();

        /** @var MealPlan|null $plan */
        $plan = $subscriber->mealPlans()->where('status', 'active')->with(['meals', 'activities'])->first();

        if ($plan === null) {
            return null;
        }

        $start = $plan->start_date?->toDateString() ?? ClinicDay::ymdOf($plan->activated_at ?? $plan->created_at);

        return $start > $date->toDateString() ? null : $plan;
    }

    /** @return list<array{name: string, name_ar: string, window: string}> */
    private function meals(MealPlan $plan, CarbonImmutable $date): array
    {
        // day_index: null = every day; otherwise 0 = Monday .. 6 = Sunday.
        $dayIndex = $date->isoWeekday() - 1;

        return $plan->meals
            ->filter(fn ($meal) => $meal->day_index === null || (int) $meal->day_index === $dayIndex)
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->map(fn ($meal) => [
                'name' => $meal->name,
                'name_ar' => self::MEAL_NAMES_AR[$meal->name] ?? $meal->name,
                'window' => self::MEAL_WINDOWS[$meal->name] ?? '',
            ])
            ->values()
            ->all();
    }

    /** @return list<array{title: string, duration_minutes: int, times_per_day: int}> */
    private function activities(MealPlan $plan): array
    {
        return $plan->activities
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->map(fn ($activity) => [
                'title' => $activity->title,
                'duration_minutes' => (int) $activity->duration_minutes,
                'times_per_day' => (int) $activity->times_per_day,
            ])
            ->values()
            ->all();
    }

    /** @return list<array{type: string, text_ar: string}> */
    private function reminders(Subscriber $subscriber, CarbonImmutable $date): array
    {
        $today = $date->toDateString();
        $reminders = [];

        $schedule = SubscriberMeasurementSchedule::query()->where('subscriber_id', $subscriber->id)->first();
        if ($schedule !== null && $schedule->next_due_on->toDateString() <= $today) {
            $reminders[] = [
                'type' => 'measurement',
                'text_ar' => $schedule->next_due_on->toDateString() === $today ? 'اليوم موعد قياساتك الجسدية.' : 'قياساتك الجسدية متأخرة، سجّلها لما تقدر.',
            ];
        }

        if (PatientCheckin::query()->where('subscriber_id', $subscriber->id)->where('status', PatientCheckin::STATUS_PENDING)->exists()) {
            $reminders[] = ['type' => 'checkin', 'text_ar' => 'عندك استبيان قصير من أخصائيتك.'];
        }

        if (PatientQuickRequest::query()->where('subscriber_id', $subscriber->id)
            ->where('status', PatientQuickRequest::STATUS_ANSWERED)->whereNull('response_seen_at')->exists()) {
            $reminders[] = ['type' => 'quick_request', 'text_ar' => 'وصلك رد من أخصائيتك على طلبك.'];
        }

        $appointment = Appointment::query()->where('subscriber_id', $subscriber->id)->where('status', 'booked')
            ->whereBetween('starts_at', [ClinicDay::startUtc($today), ClinicDay::endUtc($today)])
            ->orderBy('starts_at')->first();
        if ($appointment !== null) {
            $time = $appointment->starts_at->setTimezone(ClinicDay::timezone())->format('H:i');
            $reminders[] = ['type' => 'appointment', 'text_ar' => "عندك موعد مع أخصائيتك اليوم الساعة {$time}."];
        }

        $unread = $this->posts->unreadCount($subscriber);
        if ($unread > 0) {
            $reminders[] = ['type' => 'posts', 'text_ar' => $this->unreadPostsText($unread)];
        }

        // Phase 4, C5: "how did you sleep?", one reminder, until last night is logged. Only for a
        // patient who has used the log in the last 14 days: new users are not nagged.
        if ($this->wellbeing->find($subscriber, $today) === null && $this->wellbeing->loggedWithin($subscriber, PatientWellbeingLog::SUMMARY_DAYS, $date)) {
            $reminders[] = ['type' => 'wellbeing', 'text_ar' => 'كيف كان نومك مبارح؟'];
        }

        return $reminders;
    }

    private function unreadPostsText(int $count): string
    {
        return 'عندك '.ArabicCount::newTips($count).' من أخصائيتك.';
    }

    private function motivation(Subscriber $subscriber, CarbonImmutable $date): ?string
    {
        $lines = array_values((array) config('motivation.lines'));

        if ($lines === []) {
            return null;
        }

        return $lines[($subscriber->id + $date->dayOfYear) % count($lines)];
    }
}
