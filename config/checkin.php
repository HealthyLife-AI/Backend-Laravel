<?php

/*
|--------------------------------------------------------------------------
| Periodic check-in questionnaire (Phase 2, A)
|--------------------------------------------------------------------------
|
| PENDING CLINICAL REVIEW: the questions, their options and the flag rules
| below are the pilot nutritionist's draft. Question keys and option values
| are stable identifiers the app and stored answers rely on: change a LABEL
| freely, but a key or value only together with a new `version`.
|
| Answers are validated against this file on the server, and the version they
| were validated against is stored with them.
|
| types: single (one option), multi (up to `max` options), text (free, `max` characters).
|
*/

return [

    'version' => 1,

    'questions' => [
        [
            'key' => 'adherence',
            'type' => 'single',
            'required' => true,
            'label' => 'كيف كان التزامك بالخطة هالفترة؟',
            'options' => [
                ['value' => 'excellent', 'label' => 'ممتاز'],
                ['value' => 'good', 'label' => 'جيد'],
                ['value' => 'fair', 'label' => 'متوسط'],
                ['value' => 'poor', 'label' => 'ضعيف'],
            ],
        ],
        [
            'key' => 'barriers',
            'type' => 'multi',
            'required' => true,
            'max' => 3,
            'label' => 'شو أكثر شي صعّب عليك؟',
            'options' => [
                ['value' => 'time', 'label' => 'الوقت'],
                ['value' => 'food_availability', 'label' => 'توفر الأكل'],
                ['value' => 'budget', 'label' => 'الميزانية'],
                ['value' => 'occasions', 'label' => 'المناسبات والعزايم'],
                ['value' => 'hunger', 'label' => 'الجوع والشهية'],
                ['value' => 'mood', 'label' => 'المزاج والتوتر'],
                ['value' => 'work_study', 'label' => 'الدوام أو الدراسة'],
                // Exclusive: chosen on its own.
                ['value' => 'none', 'label' => 'ولا شي'],
            ],
        ],
        [
            'key' => 'appetite',
            'type' => 'single',
            'required' => true,
            'label' => 'كيف كانت شهيتك؟',
            'options' => [
                ['value' => 'lower', 'label' => 'أقل من العادة'],
                ['value' => 'normal', 'label' => 'طبيعية'],
                ['value' => 'higher', 'label' => 'أكثر من العادة'],
            ],
        ],
        [
            'key' => 'sleep',
            'type' => 'single',
            'required' => true,
            'label' => 'كيف كان نومك؟',
            'options' => [
                ['value' => 'good', 'label' => 'جيد'],
                ['value' => 'interrupted', 'label' => 'متقطع'],
                ['value' => 'short', 'label' => 'قليل'],
            ],
        ],
        [
            'key' => 'note',
            'type' => 'text',
            'required' => false,
            'max' => 280,
            'label' => 'شي بتحب تحكيه لأخصائيتك؟',
        ],
    ],

    /*
    | A submitted check-in is FLAGGED (and a checkin_flag alert opens) when any
    | answer matches: question key => the values that flag it.
    */
    'flag_rules' => [
        'adherence' => ['poor'],
        'barriers' => ['hunger', 'mood'],
        'appetite' => ['higher'],
    ],

    /** A pending check-in older than this many days is closed as expired (no alert). */
    'expire_after_days' => 7,

];
