<?php

return [

    /*
    |--------------------------------------------------------------------------
    | JWT Secret
    |--------------------------------------------------------------------------
    |
    | Signing secret for access tokens (HS256). Must be distinct from APP_KEY
    | and set per environment. Generate one with:
    |   php -r "echo 'base64:'.base64_encode(random_bytes(32));"
    |
    */

    'secret' => env('JWT_SECRET'),

    'algo' => 'HS256',

    /*
    |--------------------------------------------------------------------------
    | Token Lifetimes (minutes)
    |--------------------------------------------------------------------------
    |
    | Access token is intentionally short-lived (SRS NFR-02 / MVP spec §8:
    | "توكن قصير العمر"). The refresh token is a long-lived, single-use,
    | rotating opaque token stored hashed in the database (FR-05).
    |
    */

    'ttl' => (int) env('JWT_TTL', 15),

    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 60 * 24 * 30),

    /*
    |--------------------------------------------------------------------------
    | Account Lockout (FR-04)
    |--------------------------------------------------------------------------
    |
    | Lock the account after this many consecutive failed login attempts,
    | for the given number of minutes.
    |
    */

    'max_login_attempts' => (int) env('JWT_MAX_LOGIN_ATTEMPTS', 5),

    'lockout_minutes' => (int) env('JWT_LOCKOUT_MINUTES', 15),

];
