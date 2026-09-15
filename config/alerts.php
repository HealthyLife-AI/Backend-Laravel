<?php

return [

    /*
    |--------------------------------------------------------------------------
    | No-log alert (FR-20)
    |--------------------------------------------------------------------------
    |
    | NOT a placeholder — FR-20 and the S5-01 task both state this number
    | directly ("no log for 3 days"). Reuses config('adherence.late_after_days')
    | rather than a second copy of the same literal: that value already means
    | exactly this, and a second independent "3" would only invite the two to
    | drift apart under a future config change.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Calorie-exceeded alert (FR-20)
    |--------------------------------------------------------------------------
    |
    | NOT a placeholder — "calories exceeded 3 days running" is stated
    | directly in FR-20 and PRD F-7. The streak is measured against each
    | day's own logged intake versus that client's daily_calorie_needs
    | (health_profiles), over CONSECUTIVE calendar dates that each have at
    | least one log — a day with no logs breaks the streak rather than
    | counting as "not exceeded", since nothing was actually measured.
    |
    */

    'calorie_exceeded_streak_days' => (int) env('ALERTS_CALORIE_EXCEEDED_STREAK_DAYS', 3),

    /*
    |--------------------------------------------------------------------------
    | Milestone alert (FR-20)
    |--------------------------------------------------------------------------
    |
    | ⚠️ PLACEHOLDER. FR-20 names "milestone reached" and the task gives one
    | example ("weight-goal progress") but neither document defines what
    | counts as one, and the schema has no target-weight field to measure
    | progress against a goal numerically. Implemented as: for a
    | weight_loss/weight_gain goal, weight moved by at least
    | `weight_change_kg` in the goal's direction between the earliest and
    | latest body-composition reading within `window_days`. No milestone
    | rule fires for weight_maintenance or health_monitoring goals — there
    | is no stored target or band to measure "progress" against for
    | either, and inventing one would be a bigger placeholder than a
    | number. This is a genuine gap for those two goals, not a hidden one.
    |
    | `window_days` (14) is not arbitrary: one interviewed nutritionist
    | described a bi-weekly circumference-measurement cadence (PRD, Open
    | Decisions) — reused here as the review period a milestone is
    | measured over, rather than inventing an unrelated number. The 2kg
    | threshold has no such source and should be put to a nutritionist
    | the same way the 70% adherence reference and the 10pp decline
    | threshold were (see S4-14's precedent).
    |
    */

    'milestone_weight_change_kg' => (float) env('ALERTS_MILESTONE_WEIGHT_CHANGE_KG', 2.0),

    'milestone_window_days' => (int) env('ALERTS_MILESTONE_WINDOW_DAYS', 14),

];
