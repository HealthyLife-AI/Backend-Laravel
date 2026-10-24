<?php

/*
|--------------------------------------------------------------------------
| Goal projection (Phase 2, C)
|--------------------------------------------------------------------------
|
| PROVISIONAL, PENDING CLINICAL REVIEW. An ESTIMATE of when a patient could
| reach their target weight, not a promise: the model below is a rule of thumb
| (about 7700 kcal per kg of body weight), body weight moves daily with water
| and salt, and the nutritionist, not this number, assesses progress.
|
| The weekly change at full adherence is the daily energy gap between
| maintenance and the target the patient is on, times 7, over kcal_per_kg,
| capped. Each scenario scales it by an adherence level.
|
*/

return [

    'kcal_per_kg' => 7700,

    /** Weekly change is never projected above these, whatever the energy gap says. */
    'max_weekly_loss_kg' => 1.0,

    'max_weekly_gain_kg' => 0.5,

    /** A scenario whose weekly change falls under this is "not reachable". */
    'min_weekly_change_kg' => 0.05,

    /** level => the adherence percent that scenario assumes. */
    'scenarios' => ['high' => 90, 'medium' => 60, 'low' => 30],

    /** The patient's actual adherence is measured over this many days, ending today. */
    'adherence_window_days' => 14,

    /** The observed weekly trend needs at least this many weights spanning at least this many days, taken from the last window_days. */
    'trend' => ['min_readings' => 4, 'min_span_days' => 21, 'window_days' => 60],

    'disclaimer_ar' => 'تقدير تقريبي يعتمد على التزامك، والوزن يتغير يوميًا بسبب الماء والأملاح. أخصائيتك هي اللي بتقيّم تقدمك.',

];
