<?php

/*
|--------------------------------------------------------------------------
| Minimum supported patient-app version (audit MO-06)
|--------------------------------------------------------------------------
|
| The patient app sends `X-App-Version` (and `X-App-Platform`: android|ios)
| on every request. An app OLDER than `min_supported` for its platform gets
| 426 `app_update_required` on the me/* and auth/* routes and shows a
| blocking update screen. A request with no header, or one that does not
| parse, is never blocked: builds that never sent it keep working, and so
| does the dashboard.
|
| The default 0.0.0 blocks nobody. Raise it ONLY when an incompatible change
| ships, with `dokku config:set <app> MIN_APP_VERSION_ANDROID=1.5.0`; it
| takes effect at the next request (no deploy needed beyond the config).
*/

return [
    'min_supported' => [
        'android' => env('MIN_APP_VERSION_ANDROID', '0.0.0'),
        'ios' => env('MIN_APP_VERSION_IOS', '0.0.0'),
    ],

    // Newest published build, for a soft "update available" hint. Optional.
    'latest' => [
        'android' => env('LATEST_APP_VERSION_ANDROID'),
        'ios' => env('LATEST_APP_VERSION_IOS'),
    ],

    // Where the update screen sends the patient. Optional until a store listing exists.
    'store_url' => [
        'android' => env('APP_STORE_URL_ANDROID'),
        'ios' => env('APP_STORE_URL_IOS'),
    ],
];
