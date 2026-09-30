<?php

namespace App\Services\Logs;

use App\Models\BodyCompositionReading;
use App\Models\MealItem;
use App\Models\MealLog;
use App\Models\Subscriber;
use App\Services\Adherence\AdherenceService;
use Carbon\CarbonInterface;

/**
 * Writing a patient's own entries: a meal log (BR-16 meal type, BR-19 late
 * mark) and a new self-reported reading, plus the dashboard columns a log
 * moves. The API controllers and `demo:seed` both write through here, so
 * seeded entries get meal_type, is_late and adherence the same way.
 */
class PatientEntryService
{
    public function __construct(
        private readonly AdherenceService $adherence,
        private readonly LogWindow $window,
        private readonly MealTypeInference $mealTypes,
    ) {}

    /**
     * BR-16: the plan meal when on-plan, else the meal type sent
     * (case-insensitive), else the meal whose time range holds `$at` in its
     * own timezone.
     */
    public function mealType(?MealItem $planItem, mixed $requested, CarbonInterface $at): string
    {
        if ($planItem !== null) {
            return $planItem->meal->name;
        }

        return $this->mealTypes->normalise($requested) ?? $this->mealTypes->infer($at);
    }

    /**
     * @param  array<string, mixed>  $attributes  food_id, meal_item_id, quantity_grams, idempotency_key
     */
    public function logMeal(Subscriber $subscriber, array $attributes, CarbonInterface $loggedAt, string $mealType): MealLog
    {
        // BR-19: accepted and marked late past the late threshold; refused
        // only past the rejection limit (a wrong device clock).
        $this->window->assertNotTooOld($loggedAt, 'logged_at');

        $log = new MealLog($attributes);
        $log->meal_type = $mealType;
        $log->subscriber_id = $subscriber->id;
        $log->is_late = $this->window->isLate($loggedAt);
        // Eloquent writes a datetime's wall clock as-is, so an offset left
        // on it would be saved as if it were app time.
        $log->logged_at = $loggedAt->copy()->setTimezone(config('app.timezone'));
        $log->save();

        $this->syncSubscriber($subscriber);

        return $log;
    }

    /**
     * A new self-reported reading for a day that has none yet (BR-19 late
     * mark and rejection limit apply).
     *
     * @param  array<string, mixed>  $measurements  Only BodyCompositionReading::CLIENT_MEASURABLE fields.
     */
    public function recordNewReading(Subscriber $subscriber, CarbonInterface $date, array $measurements): BodyCompositionReading
    {
        $this->window->assertDateNotTooOld($date, 'recorded_at');

        return $subscriber->bodyCompositionReadings()->create($measurements + [
            'recorded_at' => $date->toDateString(),
            'source' => BodyCompositionReading::SOURCE_SELF,
            'is_late' => $this->window->isDateLate($date),
        ]);
    }

    /**
     * Brings `last_logged_at` and `adherence_status` in line with the logs
     * that exist now. Recomputed from the logs rather than set from the one
     * just written, so a late offline entry, an edit or a delete all leave
     * the right value.
     */
    public function syncSubscriber(Subscriber $subscriber): void
    {
        $subscriber->forceFill(['last_logged_at' => $subscriber->mealLogs()->max('logged_at')])->save();

        $this->adherence->refreshStatus($subscriber);
    }
}
