<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API rate limit for the demo accounts, during a load test
    |--------------------------------------------------------------------------
    |
    | Every authenticated account may make 120 API requests a minute (AppServiceProvider, 'api').
    | The load test (load-tests/) signs in as only three demo patients and one demo nutritionist,
    | so with the normal limit it would be throttled at roughly 6 requests a second in total and say
    | nothing about the server. Set LOAD_TEST_DEMO_API_RATE_PER_MINUTE (for example 3000) in the
    | environment for the night of the test and UNSET it afterwards. It only ever applies to accounts
    | flagged is_demo; every other account keeps 120.
    |
    */

    'demo_api_requests_per_minute' => (int) env('LOAD_TEST_DEMO_API_RATE_PER_MINUTE', 120),

];
