<?php

// Patient notification texts. Deliberately generic: a push passes through
// Google (FCM), so no note, weight, food or other health detail goes here.
// The full content lives only in the inbox and the review endpoints.
return [
    'plan_activated' => ['title' => 'خطتك الغذائية جاهزة', 'body' => 'اعتمد أخصائيك خطتك. افتح التطبيق لمشاهدتها.'],
    'plan_updated' => ['title' => 'تحديث على خطتك', 'body' => 'عدّل أخصائيك خطتك الغذائية. افتح التطبيق لمشاهدتها.'],
    'follow_up_resumed' => ['title' => 'عادت متابعتك', 'body' => 'استأنف أخصائيك متابعتك. يمكنك متابعة خطتك وتسجيل وجباتك.'],
    'review_new' => ['title' => 'لديك ملاحظة جديدة من أخصائيك', 'body' => 'افتح التطبيق للاطلاع عليها.'],
    'review_updated' => ['title' => 'حدّث أخصائيك ملاحظاته لك', 'body' => 'افتح التطبيق للاطلاع على التحديث.'],
    'log_reminder' => ['title' => 'لا تنسَ تسجيل وجباتك اليوم', 'body' => 'لم تسجّل أي وجبة اليوم بعد.'],
];
