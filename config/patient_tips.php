<?php

/*
|--------------------------------------------------------------------------
| Ready-made patient tips (clinical batch 1, D2)
|--------------------------------------------------------------------------
|
| Text supplied by the pilot nutritionist, keyed by condition code plus
| "general". The plan designer offers these as chips: clicking one appends
| it to the plan's patient instructions, where she can edit it before
| saving. The tips are only suggestions; nothing here is sent anywhere by
| itself.
|
*/

$diabetes = [
    'مارس رياضة خفيفة بشكل منتظم، مثل المشي.',
    'قِس مستوى السكر قبل الوجبات وبعدها.',
    'التزم بأنواع الأطعمة والسناكات المسموحة في خطتك.',
];

return [

    'general' => [
        'حافظ على هدوئك وخصص وقتًا يوميًا لتمارين التنفس.',
    ],

    'diabetes_type1' => $diabetes,
    'diabetes_type2' => $diabetes,
    'gestational_diabetes' => $diabetes,
    'insulin_resistance' => $diabetes,
    'prediabetes' => $diabetes,

    // PENDING CLINICAL REVIEW: celiac and fluid_retention (Phase 1, D4). The
    // text is the pilot nutritionist's draft; the nutritionist can edit any
    // inserted line before saving.
    'celiac' => [
        'تجنب القمح والشعير والجاودار وكل ما يُصنع منها، واقرأ الملصق الغذائي لكل منتج جاهز.',
        'انتبه للتلوث: استخدم أدوات وأسطح منفصلة عند تحضير أكلك.',
        'احرص على مصادر الحديد والكالسيوم وفيتامين د، والتزم بالفحوصات الدورية التي يطلبها طبيبك.',
    ],

    'fluid_retention' => [
        'قلّل الملح والأطعمة المصنّعة والمعلبة والمخللات.',
        'اشرب الماء بانتظام حسب توجيهات أخصائيك، ولا تقلل الماء من تلقاء نفسك.',
        'حرّك ساقيك وارفعهما عند الجلوس لفترات طويلة.',
    ],

    'hypertension' => [
        'قلّل الصوديوم والأطعمة المصنّعة، وفضّل الأكل الطبيعي والطازج.',
        'انتبه لمصادر الصوديوم المخفية: مرقة الدجاج الجاهزة، الأطعمة المعلبة، المخللات، الجبنة البيضاء، الكولا ومشروبات الطاقة.',
        'قلّل اللحوم الحمراء.',
    ],

];
