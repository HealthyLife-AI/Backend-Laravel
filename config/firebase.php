<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging (S5-06 / FR-22)
    |--------------------------------------------------------------------------
    |
    | Two ways to supply the service-account JSON from Firebase console
    | (Project settings → Service accounts → Generate new private key).
    | The file itself is never committed — see storage/app/firebase in
    | .gitignore. project_id is read out of the JSON itself rather than
    | duplicated in a second env var, so there is exactly one source for
    | it.
    |
    | `credentials_path` — a file on local disk. Works for local dev,
    | where the file genuinely sits on the same machine PHP runs on.
    |
    | `credentials_json` — the file's raw content, pasted directly as an
    | env var value, one line. This is the one that actually works on a
    | PaaS deploy (Taqat/Dokku): a git-push build has nowhere to receive
    | an uploaded file at all, and the gitignored file can't ride along
    | in the repo — so the JSON has to travel as the env var itself, the
    | same way OPENAI_API_KEY is a value, not a path to a file holding
    | one. `credentials_json` is tried first if both are set.
    |
    | Blank in every environment until one of these is set: FcmPushService
    | treats "not configured" the same way OpenAiCompatibleClient does —
    | fails closed (logs, does not throw) rather than blocking whatever
    | triggered the push.
    |
    */

    'credentials_json' => env('FIREBASE_CREDENTIALS_JSON'),

    'credentials_path' => env('FIREBASE_CREDENTIALS_PATH'),

];
