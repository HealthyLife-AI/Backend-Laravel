<?php

return [

    /*
    | Passwords for the accounts `php artisan demo:seed` creates. Both are optional:
    | an unset one is replaced by a random password that the command prints once.
    | Never commit a value; set them in the environment only.
    |
    |   nutritionist_password  the demo nutritionist's dashboard login
    |   patient_password       all four demo patients (the app-store reviewer signs in as one)
    */

    'nutritionist_password' => env('DEMO_NUTRITIONIST_PASSWORD'),

    'patient_password' => env('DEMO_PATIENT_PASSWORD'),

];
