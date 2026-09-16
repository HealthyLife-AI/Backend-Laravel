<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging (S5-06 / FR-22)
    |--------------------------------------------------------------------------
    |
    | Three ways to supply the service-account JSON from Firebase console
    | (Project settings → Service accounts → Generate new private key).
    | The file itself is never committed — see storage/app/firebase in
    | .gitignore. project_id is read out of the JSON itself rather than
    | duplicated in a second env var, so there is exactly one source for
    | it. Precedence: base64 > raw JSON > file path — most PaaS-safe first.
    |
    | `credentials_json_base64` — the file's content, base64-encoded, one
    | line. THIS IS THE ONE TO USE ON TAQAT/DOKKU (or any PaaS whose
    | dashboard writes env vars through its own storage layer). Not a
    | theoretical precaution: setting the raw JSON directly as
    | `credentials_json` on this project's own Taqat deployment broke
    | login/register outright — the JSON is full of unescaped `"`
    | characters, and Taqat's own env-var storage mangled the value badly
    | enough to corrupt something read at every request, with no
    | exception ever reaching Laravel's own log (the corruption happened
    | before the app got a chance to). Base64 output is exactly
    | `[A-Za-z0-9+/=]` — nothing in that alphabet can be mistaken for a
    | quote, an `=` delimiter, a newline, or anything else an env-var
    | store might interpret specially.
    |
    | `credentials_json` — the raw file content as the value, no encoding.
    | Kept for a PaaS that genuinely passes env vars through untouched
    | (real environment variables, not reinterpreted). Prefer the base64
    | form unless you have already confirmed this one is safe on your
    | specific host.
    |
    | `credentials_path` — a file on local disk. Local dev only, where the
    | file genuinely sits on the same machine PHP runs on.
    |
    | Blank in every environment until one of these is set: FcmPushService
    | treats "not configured" the same way OpenAiCompatibleClient does —
    | fails closed (logs, does not throw) rather than blocking whatever
    | triggered the push.
    |
    */

    'credentials_json_base64' => env('FIREBASE_CREDENTIALS_JSON_BASE64'),

    'credentials_json' => env('FIREBASE_CREDENTIALS_JSON'),

    'credentials_path' => env('FIREBASE_CREDENTIALS_PATH'),

];
