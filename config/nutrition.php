<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Approved-calorie floor (Item C)
    |--------------------------------------------------------------------------
    |
    | Values supplied by the clinic (the pilot nutritionist, clinical batch
    | 1): 1200 for women, 1400 for men. Still overridable per environment
    | (CALORIE_FLOOR_FEMALE / CALORIE_FLOOR_MALE). The designer's floor is
    | only a SOFT warning:
    | approving a lower target is a clinical decision (a very short
    | protocol, a small patient, a specific condition) and the app has no
    | standing to block it.
    |
    | The goal-adjusted SUGGESTION (see `weight_loss_deficit` below) is also
    | never lower than this floor.
    |
    | This file is the ONLY source: the dashboard holds no copy and reads
    | them from `calorie_floor` on GET/PUT clients/{id}/health-profile
    | (HealthProfileResource), so changing a value here or in the env
    | changes the warning with no dashboard release.
    |
    */

    'calorie_floor' => [
        'female' => env('CALORIE_FLOOR_FEMALE', 1200),
        'male' => env('CALORIE_FLOOR_MALE', 1400),
    ],

    /*
    |--------------------------------------------------------------------------
    | BMI classes (clinical batch 1, B1)
    |--------------------------------------------------------------------------
    |
    | PROVISIONAL until the nutritionist confirms them. Lower bound of each
    | class, adults only. Below `overweight` there is no deficit anyway.
    |
    */

    'bmi_classes' => [
        'underweight' => 0.0,
        'normal' => 18.5,
        'overweight' => 25.0,
        'obesity_1' => 30.0,
        'obesity_2' => 35.0,
        'obesity_3' => 40.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Goal-adjusted calorie suggestion (clinical batch 1, B2)
    |--------------------------------------------------------------------------
    |
    | Kcal/day taken off the maintenance figure for a weight-loss goal, by
    | BMI class. The numbers are the pilot nutritionist's. A weight-gain
    | surplus is still pending from her: while it is null no surplus is
    | applied. Patients with a condition listed in `no_auto_adjust_conditions`
    | (and under-18s) get NO automatic adjustment: that is a clinical call.
    |
    */

    'weight_loss_deficit' => [
        'overweight' => 500,
        'obesity_1' => 500,
        'obesity_2' => 650,
        'obesity_3' => 1000,
    ],

    'weight_gain_surplus' => null,

    'no_auto_adjust_conditions' => [
        'diabetes_type1',
        'gestational_diabetes',
        'kidney_disease',
        'liver_disease',
        'cancer',
        'diabetes_type2',
        'hypertension',
    ],

    /*
    |--------------------------------------------------------------------------
    | Glycemic index / load and sodium (clinical batch 1, E)
    |--------------------------------------------------------------------------
    |
    | Category cut-offs for glycemic index (low <= 55, medium 56-69, high
    | >= 70) and glycemic load per serving (low <= 10, medium 11-19, high
    | >= 20). `high_sodium_mg_per_100g` (600 mg sodium per 100 g) matches the
    | UK Food Standards Agency front-of-pack "high" threshold for foods:
    | more than 1.5 g salt per 100 g, i.e. 600 mg sodium. Drinks have a
    | lower FSA threshold and are NOT treated separately here.
    |
    | Warnings use these only for foods a nutritionist has reviewed
    | (foods.nutrition_reviewed_at), and are never blocking.
    |
    */

    'glycemic_index' => ['low_max' => 55, 'medium_max' => 69],

    'glycemic_load' => ['low_max' => 10, 'medium_max' => 19],

    'high_sodium_mg_per_100g' => env('HIGH_SODIUM_MG_PER_100G', 600),

    /*
    | Conditions whose plans get the high-sodium warning (Phase 1, D3). A
    | config list so the clinic can change it without a code change.
    */

    'sodium_conditions' => ['hypertension', 'fluid_retention', 'kidney_disease'],

    /*
    | When a plan may be CALLED low-carb / high-protein / low-fat (the AI plan
    | summary note, Phase 3 review). Shares of the plan's macro energy, in
    | percent. PENDING CLINICAL REVIEW. The note is only allowed that label when
    | its share crosses the line: low_carb below `low_carb_max`, high_protein above
    | `high_protein_min`, low_fat below `low_fat_max`. No other macro label is
    | allowed at all.
    */

    'macro_labels' => ['low_carb_max' => 26, 'high_protein_min' => 30, 'low_fat_max' => 20],

];
