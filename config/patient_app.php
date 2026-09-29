<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Edit window (BR-15)
    |--------------------------------------------------------------------------
    |
    | How many days after a meal log's `logged_at` (or the start of a
    | self-reported reading's date) the patient may still edit or delete it.
    | Past this the entry is locked (403 `log_locked`), so a patient can't
    | quietly rewrite history their nutritionist has already reviewed. An
    | edit is recorded in `edited_at` and shown to the nutritionist.
    |
    */

    'edit_window_days' => (int) env('LOG_EDIT_WINDOW_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Late entries (BR-19)
    |--------------------------------------------------------------------------
    |
    | Applies to meal logs and self-reported readings made by the PATIENT
    | (never to the nutritionist's clinic readings, which may be any past
    | date). An entry dated more than `late_after_days` back is accepted and
    | marked `is_late`, so an offline queue that syncs late never loses data
    | and the nutritionist can see it came in late. Only an entry dated more
    | than `reject_after_days` back is refused (422 `entry_too_old`): at that
    | age it is almost certainly a wrong device clock, not a real meal.
    |
    */

    'late_after_days' => (int) env('LOG_LATE_AFTER_DAYS', 7),

    'reject_after_days' => (int) env('LOG_REJECT_AFTER_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Meal times (BR-16)
    |--------------------------------------------------------------------------
    |
    | An off-plan meal log sent without a meal_type is filed under the meal
    | whose time range contains the LOCAL time of its logged_at: the offset
    | sent with logged_at, or the app timezone (config app.timezone) when it
    | carries none. Anything outside these ranges is a snack. Each range is
    | "HH:MM-HH:MM", both ends inclusive, within one day.
    |
    */

    'meal_times' => [
        'breakfast' => env('MEAL_TIME_BREAKFAST', '05:00-10:59'),
        'lunch' => env('MEAL_TIME_LUNCH', '11:00-16:59'),
        'dinner' => env('MEAL_TIME_DINNER', '17:00-22:59'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Patient consent (BR-17)
    |--------------------------------------------------------------------------
    |
    | `version` is the CURRENT privacy-policy version. Until a patient has
    | accepted it, their data endpoints answer 403 `consent_required`. Bump
    | CONSENT_VERSION (e.g. to 2027-02-01) when the policy text changes and
    | every patient is asked again.
    |
    | It always has a value: a blank or missing CONSENT_VERSION falls back to
    | the version below, so the gate is always on and one missing variable
    | can't lock patients out of the app. The admin overview warns while
    | either value comes from a default rather than the environment.
    |
    | `policy_url` is where the app sends the patient to read the policy (the
    | text itself is not stored or written by this code). Without
    | CONSENT_POLICY_URL it is the dashboard's /privacy page on FRONTEND_URL;
    | with neither set it is null, and the gate still works.
    |
    */

    'consent' => [
        'version' => env('CONSENT_VERSION') ?: '2026-10-01',
        'policy_url' => env('CONSENT_POLICY_URL')
            ?: (env('FRONTEND_URL') ? rtrim((string) env('FRONTEND_URL'), '/').'/privacy' : null),
        // Whether each came from the environment rather than a default;
        // read only by the admin overview's warning.
        'version_from_env' => filled(env('CONSENT_VERSION')),
        'policy_url_from_env' => filled(env('CONSENT_POLICY_URL')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Account deletion (BR-18)
    |--------------------------------------------------------------------------
    |
    | `DELETE /me/account` needs the current password, so a stolen access
    | token alone can't wipe a patient's record. This caps attempts per
    | minute per patient so the password can't be guessed through it.
    |
    */

    'account_deletion' => [
        'attempts_per_minute' => (int) env('ACCOUNT_DELETION_ATTEMPTS_PER_MINUTE', 5),
    ],

];
