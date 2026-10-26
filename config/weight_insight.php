<?php

/*
|--------------------------------------------------------------------------
| Weight-change insight (Phase 3, C)
|--------------------------------------------------------------------------
|
| PENDING CLINICAL REVIEW: every threshold, the blocklist and the fallback
| texts below are a draft for the pilot nutritionist to confirm.
|
| The insight explains the change between the two latest weight readings in
| a sentence or two. The numbers are computed on the server; the model only
| words them, and its reply is thrown away (for the fallback text) when it
| invents a number, runs long, or uses a word from the blocklist.
|
*/

return [

    /** The window between two readings: at least this many days (an earlier reading is used when the latest pair is closer), and at most this many (older pairs are not explained). */
    'min_days' => 5,

    'max_days' => 28,

    /** A change under this many kg over `water_max_days` or fewer is plausibly water, not tissue. */
    'water_change_kg' => 1.0,

    'water_max_days' => 7,

    /** This many logs of high-sodium foods in the window also suggest a water fluctuation. */
    'water_high_sodium_logs' => 3,

    /** Adherence (percent of logged meals on the plan) at or above this counts as following the plan. */
    'consistent_adherence_percent' => 70,

    'max_chars' => 350,

    /** Always ends the text; added when the model leaves it out. */
    'closing_ar' => 'وأخصائيتك بتتابع معك.',

    /**
     * The wording of the fixed texts changes when this number does: an insight stored under an older
     * version is regenerated on its next read (the old texts may carry wording the rules now refuse).
     * 2: Palestinian dialect, qualitative figures, addressed by gender.
     * 3: the short_sleep fact (Phase 4).
     */
    'text_version' => 3,

    /** A night under this many hours of sleep is a short night. */
    'short_sleep_hours' => 6,

    /** This many short nights in the window set the `short_sleep` fact (qualitative in the text, never a number). */
    'short_sleep_nights' => 3,

    /**
     * Egyptian forms the Palestinian audience does not use: a reply with any of these as a word
     * (optionally after و / ف, "وده") is thrown away for the fixed text. Matched exactly, with no other
     * prefix or suffix, so Levantine words that contain them ("بدي") are left alone.
     */
    'dialect_blocklist' => ['ده', 'دي', 'كده', 'إزاي', 'عشان كده'],

    /** A reply containing any of these (substring) is rejected. No medical, diagnostic or calorie talk. */
    'blocklist' => ['دواء', 'أدوية', 'ادوية', 'أدويت', 'مرض', 'سكري', 'سكر', 'ضغط', 'تشخيص', 'سعرة', 'سعرات', 'علاج', 'كالوري'],

    /*
    | Used when the model is not configured, fails, or its reply is rejected.
    | Placeholders: :change (e.g. "نزل وزنك حوالي 1.2 كغ", or "وزنك ثابت تقريبًا"
    | when it did not move), :days (already with its noun in correct Arabic agreement: يوم واحد، يومين، 5 أيام، 12 يومًا). Picked by the facts' booleans: water
    | fluctuation first, then consistent with the plan, then neutral.
    */
    'fallback' => [
        'water_fluctuation' => [
            'male' => ':change خلال :days. هاد التغيّر عادة بيكون من الماء والملح وبيطلع وبينزل من يوم ليوم، فما تقلق منه. وأخصائيتك بتتابع معك.',
            'female' => ':change خلال :days. هاد التغيّر عادة بيكون من الماء والملح وبيطلع وبينزل من يوم ليوم، فما تقلقي منه. وأخصائيتك بتتابع معك.',
            'unknown' => ':change خلال :days. هاد التغيّر عادة بيكون من الماء والملح وبيطلع وبينزل من يوم ليوم، فمش مدعاة للقلق. وأخصائيتك بتتابع معك.',
        ],
        'consistent' => [
            'male' => ':change خلال :days، وهاد بيمشي مع التزامك بالخطة. كمّل على نفس الطريقة. وأخصائيتك بتتابع معك.',
            'female' => ':change خلال :days، وهاد بيمشي مع التزامك بالخطة. كمّلي على نفس الطريقة. وأخصائيتك بتتابع معك.',
            'unknown' => ':change خلال :days، وهاد بيمشي مع التزامك بالخطة. خلّينا نكمّل على نفس الطريقة. وأخصائيتك بتتابع معك.',
        ],
        'neutral' => [
            'male' => ':change خلال :days. الوزن بيتأثر بأشياء كتير غير الأكل، فالمهم الاتجاه على المدى الطويل. وأخصائيتك بتتابع معك.',
            'female' => ':change خلال :days. الوزن بيتأثر بأشياء كتير غير الأكل، فالمهم الاتجاه على المدى الطويل. وأخصائيتك بتتابع معك.',
            'unknown' => ':change خلال :days. الوزن بيتأثر بأشياء كتير غير الأكل، فالمهم الاتجاه على المدى الطويل. وأخصائيتك بتتابع معك.',
        ],
    ],

    'change_phrase' => [
        'down' => 'نزل وزنك حوالي :kg كغ',
        'up' => 'زاد وزنك حوالي :kg كغ',
        'flat' => 'وزنك ثابت تقريبًا',
    ],

];
