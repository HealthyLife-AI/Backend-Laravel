<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Approved-calorie floor (Item C)
    |--------------------------------------------------------------------------
    |
    | PROVISIONAL, pending the nutritionists' own input (B14). These are
    | the commonly quoted "do not prescribe below this without
    | supervision" figures, not a rule this app invented or verified, and
    | they are deliberately only a SOFT warning in the plan designer:
    | approving a lower target is a clinical decision (a very short
    | protocol, a small patient, a specific condition) and the app has no
    | standing to block it.
    |
    | Replace both numbers once the clinic states its own policy.
    |
    | This file is the ONLY source: the dashboard holds no copy and reads
    | them from `calorie_floor` on GET/PUT clients/{id}/health-profile
    | (HealthProfileResource), so changing a value here or in the env
    | changes the warning with no dashboard release.
    |
    */

    'calorie_floor' => [
        'female' => env('CALORIE_FLOOR_FEMALE', 1200),
        'male' => env('CALORIE_FLOOR_MALE', 1500),
    ],

];
