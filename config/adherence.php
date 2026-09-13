<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Adherence thresholds (S4-03 / FR-18)
    |--------------------------------------------------------------------------
    |
    | The PRD specifies the three client states by colour — on-track (green),
    | needs-attention (amber), late (red) — and FR-18 defines the adherence
    | rate itself, but NEITHER document fixes the numeric boundary between
    | on-track and needs-attention. These are the implementation's choice,
    | surfaced here rather than buried as literals so a nutritionist's
    | clinical judgement can move them without a code change.
    |
    | `late_after_days` is NOT invented: it reuses the "no log for 3 days"
    | rule already specified for the Sprint 5 alert engine (FR-20), so a
    | client cannot be shown as on-track in the list while simultaneously
    | triggering a stopped-logging alert.
    |
    | `on_track_percent` has no source in the requirements and is a
    | placeholder pending nutritionist input.
    |
    */

    'late_after_days' => (int) env('ADHERENCE_LATE_AFTER_DAYS', 3),

    'on_track_percent' => (int) env('ADHERENCE_ON_TRACK_PERCENT', 70),

    /*
    | The default window used when a caller asks for adherence without
    | naming a date range. Seven days matches the dashboard's plan-vs-actual
    | comparison (S4-07) and the weekly summary cadence (FR-21).
    */

    'default_window_days' => (int) env('ADHERENCE_DEFAULT_WINDOW_DAYS', 7),

];
