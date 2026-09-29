<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Log edit window (BR-15)
    |--------------------------------------------------------------------------
    |
    | How long after a meal log's `logged_at` (or a self-reported reading's
    | date) the patient may still edit or delete it. Past this the entry is
    | locked (403 `log_locked`), so a patient can't quietly rewrite history
    | their nutritionist has already reviewed.
    |
    */

    'edit_window_hours' => (int) env('LOG_EDIT_WINDOW_HOURS', 48),

    /*
    |--------------------------------------------------------------------------
    | Backdating limit (BR-19)
    |--------------------------------------------------------------------------
    |
    | How far in the past a new meal log or self-reported reading may be
    | dated. Long enough that the app's offline queue still syncs after a
    | week without signal; short enough that old history can't be filled in
    | after the fact (422 `entry_too_old`).
    |
    */

    'backdate_limit_days' => (int) env('LOG_BACKDATE_LIMIT_DAYS', 7),

];
