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
    | How far in the past a new meal log or self-reported reading made by the
    | PATIENT may be dated. It does not apply to the nutritionist's clinic
    | readings (POST /clients/{id}/body-composition-readings), who may enter
    | paper records of any past date when onboarding a patient.
    |
    | Long enough that the app's offline queue still syncs after a week
    | without signal; short enough that old history can't be filled in after
    | the fact (422 `entry_too_old`).
    |
    */

    'backdate_limit_days' => (int) env('LOG_BACKDATE_LIMIT_DAYS', 7),

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
