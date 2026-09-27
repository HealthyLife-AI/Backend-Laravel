<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Timezone the scheduled jobs' clock times are read in
    |--------------------------------------------------------------------------
    |
    | The app itself stays on UTC (stored timestamps are unchanged). This only
    | decides what "06:00", "20:00" and "Monday 07:00" mean for the alert,
    | reminder and weekly-summary jobs — they are clinic-local times, so a
    | 20:00 reminder must not land at 23:00 for the people it's for.
    |
    | ⚠️ PLACEHOLDER default: the product targets Arabic-speaking clinics
    | (UTC+3 for most of the target region). Set SCHEDULE_TIMEZONE per
    | deployment to the pilot clinic's actual zone.
    |
    */

    'timezone' => env('SCHEDULE_TIMEZONE', 'Asia/Riyadh'),

    /*
    |--------------------------------------------------------------------------
    | Self-triggering (no cron required)
    |--------------------------------------------------------------------------
    |
    | Laravel's scheduler only fires when something runs `schedule:run`
    | every minute (a cron entry). That is set up in `app.json` for Taqat,
    | but the pilot must not depend on it: if the platform cron isn't
    | running, any API request notices an overdue job and runs it after the
    | response is sent (see `SelfScheduler`). With cron working, the jobs
    | have already run on time and this never fires.
    |
    | Also gates re-evaluating a client's alerts right after new data about
    | them is saved (a meal log, a measurement, a health-profile change).
    |
    | Off in phpunit.xml so existing tests don't pick up side effects from
    | whichever request happens to run first; tests that cover it opt in.
    |
    */

    'self_trigger' => (bool) env('SCHEDULE_SELF_TRIGGER', true),

];
