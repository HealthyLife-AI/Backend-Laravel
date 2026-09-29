<?php

namespace App\Services\Logs;

use App\Exceptions\ApiCodeException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The two time limits on a patient's own entries, both from
 * config/patient_app.php:
 *
 *  - BR-15, the edit window: an entry can be edited or deleted for a
 *    while after its own date, then it is locked.
 *  - BR-19, the backdating limit: a new entry can't be dated further back
 *    than a set number of days.
 *
 * Shared by meal logs and self-reported measurements so both follow the
 * same rules.
 */
class LogWindow
{
    public function editHours(): int
    {
        return (int) config('patient_app.edit_window_hours');
    }

    public function backdateDays(): int
    {
        return (int) config('patient_app.backdate_limit_days');
    }

    /** BR-15: when an entry dated `$at` stops being editable. */
    public function editableUntil(CarbonInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->addHours($this->editHours());
    }

    /**
     * BR-15 for a reading, which has a date and no time of day: the window
     * runs from the start of its date, so a reading dated today stays
     * editable through the end of tomorrow.
     */
    public function readingEditableUntil(CarbonInterface $date): CarbonImmutable
    {
        return $this->editableUntil(CarbonImmutable::instance($date)->startOfDay());
    }

    public function isReadingLocked(CarbonInterface $date): bool
    {
        return CarbonImmutable::now()->greaterThan($this->readingEditableUntil($date));
    }

    /** BR-15: 403 `log_locked` once a reading's window has passed. */
    public function assertReadingEditable(CarbonInterface $date): void
    {
        if ($this->isReadingLocked($date)) {
            $this->throwLocked();
        }
    }

    /** BR-15: the earliest date an edit may move an entry to without locking it on the spot. */
    public function earliestEditableTimestamp(): CarbonImmutable
    {
        return CarbonImmutable::now()->subHours($this->editHours());
    }

    public function isLocked(CarbonInterface $at): bool
    {
        return CarbonImmutable::now()->greaterThan($this->editableUntil($at));
    }

    /** BR-15: 403 `log_locked` once the window has passed. */
    public function assertEditable(CarbonInterface $at): void
    {
        if ($this->isLocked($at)) {
            $this->throwLocked();
        }
    }

    public function throwLocked(): never
    {
        throw new ApiCodeException(
            'This entry can no longer be changed.',
            'log_locked',
            403,
            ['editable_hours' => $this->editHours()],
        );
    }

    /**
     * BR-19: 422 `entry_too_old` when a new entry is dated beyond the
     * backdating limit. `$field` names the request field the date came in
     * under so the standard `errors` shape points at it.
     */
    public function assertNotTooOld(CarbonInterface $at, string $field): void
    {
        if ($at->lessThan(CarbonImmutable::now()->subDays($this->backdateDays()))) {
            throw new ApiCodeException(
                "The {$field} is older than entries may be dated.",
                'entry_too_old',
                422,
                ['max_age_days' => $this->backdateDays()],
                [$field => ["The {$field} may not be more than {$this->backdateDays()} days in the past."]],
            );
        }
    }

    /**
     * BR-19 for a date-only entry: today minus the limit is still allowed,
     * a day earlier is not.
     */
    public function assertDateNotTooOld(CarbonInterface $date, string $field): void
    {
        $this->assertNotTooOld(
            CarbonImmutable::instance($date)->endOfDay(),
            $field,
        );
    }
}
