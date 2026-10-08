<?php

return [
    /*
    | The reverse proxy (or proxies) in front of the app — read by Laravel's
    | TrustProxies middleware. Comma-separated IPs or CIDRs, exactly the
    | proxy's address as GET /system/scheduler-status reports it in
    | request.remote_addr. Validated at boot (AppServiceProvider): "*" is
    | refused. Empty = no proxy trusted.
    */
    'proxies' => env('TRUSTED_PROXIES') ?: null,
];
