<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging (S5-06 / FR-22)
    |--------------------------------------------------------------------------
    |
    | Path to the service-account JSON downloaded from Firebase console
    | (Project settings → Service accounts → Generate new private key).
    | The file itself is never committed — see storage/app/firebase in
    | .gitignore. project_id is read out of the JSON itself rather than
    | duplicated in a second env var, so there is exactly one source for
    | it.
    |
    | Blank in every environment until this file exists there: FcmPushService
    | treats "not configured" the same way OpenAiCompatibleClient does —
    | fails closed (logs, does not throw) rather than blocking whatever
    | triggered the push.
    |
    */

    'credentials_path' => env('FIREBASE_CREDENTIALS_PATH'),

];
