<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Push fan-out (many patients at once)
    |--------------------------------------------------------------------------
    |
    | A request or a scheduled command that notifies many patients writes the
    | in-app rows at once and queues the FCM sends as jobs of this many
    | notifications each (App\Jobs\SendPushNotifications). The queue is worked
    | by the every-minute cron (bootstrap/app.php), so a push goes out within a
    | minute or so instead of holding a web worker for the whole fan-out.
    |
    | A job stops starting new sends after job_budget_seconds and queues the
    | rest as a new job, so it always ends before the worker's 60 s limit.
    |
    */

    'chunk_size' => 100,

    'job_budget_seconds' => 40,

];
