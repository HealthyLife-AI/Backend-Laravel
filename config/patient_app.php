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
    | it (e.g. 2026-10-01 -> 2027-02-01) when the policy text changes and
    | every patient is asked again.
    |
    | Blank means "not configured", and the gate must not be silently off
    | in a real deployment: outside `local` and `testing` a blank version
    | makes patient data endpoints refuse with 503 `consent_not_configured`
    | (fail closed), and the admin overview shows a warning.
    |
    | `policy_url` is where the app sends the patient to read the policy.
    | The policy text itself is not stored or written by this code.
    |
    */

    'consent' => [
        'version' => env('CONSENT_VERSION') ?: null,
        'policy_url' => env('CONSENT_POLICY_URL') ?: null,
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
