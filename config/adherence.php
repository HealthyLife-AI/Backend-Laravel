<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Adherence reference threshold (FR-30)
    |--------------------------------------------------------------------------
    |
    | 70% is a practical starting reference confirmed by a practicing
    | nutritionist (Kholod: "good as a beginning, but I watch if it drops
    | lower"). It is NOT the classifier and must not be used as one — FR-30
    | is explicit that it is "shown for context only", and BR-14 classifies
    | a client by the DIRECTION of their adherence instead.
    |
    | Renamed from `on_track_percent` in the S4-03 rework: the old name
    | described the decision it used to make, and keeping it would invite
    | exactly the level-based comparison the interviews ruled out.
    |
    */

    'reference_percent' => (int) env('ADHERENCE_REFERENCE_PERCENT', 70),

    /*
    |--------------------------------------------------------------------------
    | What counts as a material decline (BR-14)
    |--------------------------------------------------------------------------
    |
    | The drop, in percentage POINTS, between the previous period's rate and
    | the current one that marks a client as declining. 85% -> 72% is a 13pp
    | fall and is surfaced despite still being above the reference.
    |
    | ⚠️ PLACEHOLDER. Neither nutritionist was asked to quantify "material",
    | and neither the PRD nor the SRS fixes a number — BR-14 says only
    | "fallen materially". 10pp is engineering's default, chosen so a drop
    | of roughly one meal in ten registers. It carries no clinical
    | authority and should be put to Kholod and Rama the same way the 70%
    | was (see S4-14's precedent).
    |
    */

    'material_decline_pp' => (int) env('ADHERENCE_MATERIAL_DECLINE_PP', 10),

    /*
    | No log for this many days marks a client as stopped-logging. NOT a
    | placeholder: reused from FR-20's existing "no log for 3 days" alert
    | rule, so a client cannot read as stable in the list while
    | simultaneously firing a stopped-logging alert.
    */

    'late_after_days' => (int) env('ADHERENCE_LATE_AFTER_DAYS', 3),

    /*
    | The window used when a caller names no date range — and, in the S4-03
    | rework, also the length of the preceding window each period is
    | compared against. Seven days matches the dashboard's plan-vs-actual
    | comparison (S4-07) and the weekly summary cadence (FR-21).
    */

    'default_window_days' => (int) env('ADHERENCE_DEFAULT_WINDOW_DAYS', 7),

];
