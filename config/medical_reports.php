<?php

/*
|--------------------------------------------------------------------------
| Medical reports (Phase 4, A)
|--------------------------------------------------------------------------
|
| A patient can upload a lab result, an image, a prescription or a medical
| letter for their nutritionist. Health documents are the most sensitive data
| the app holds, so the feature is OFF until it is deliberately switched on:
|
|   MEDICAL_REPORTS_ENABLED=true
|
| While it is off every report endpoint answers 404 (as if it did not exist)
| and the dashboard hides the card.
|
| Files live on a PRIVATE disk (`disk`), never on a public one: no public URL,
| no storage:link. Each is stored under a random UUID path (never the original
| name), with image metadata (EXIF, GPS...) stripped, and ENCRYPTED with the
| app key (APP_KEY) in 1 MiB frames; it is decrypted only while it is streamed
| to the patient or to their own nutritionist. LOSING APP_KEY LOSES THE FILES.
|
| The default disk is `reports` (storage/app/private/reports on the server).
| On an ephemeral container (Dokku) that directory must be a persistent mount,
| or the files vanish at the next deploy: see DEPLOYMENT.md. Alternatively
| point MEDICAL_REPORTS_DISK at an S3-compatible disk (e.g. `s3`).
|
*/

return [

    'enabled' => (bool) env('MEDICAL_REPORTS_ENABLED', false),

    'disk' => env('MEDICAL_REPORTS_DISK', 'reports'),

    /** Largest file, in megabytes. The web server must allow a body this big (see DEPLOYMENT.md). */
    'max_file_mb' => (int) env('MEDICAL_REPORTS_MAX_FILE_MB', 10),

    /** Reports a patient may keep (deleted ones do not count). */
    'max_files_per_patient' => (int) env('MEDICAL_REPORTS_MAX_FILES', 30),

    /** Extension => the MIME type the file must really be (sniffed from its content, not trusted from the name). */
    'allowed_mimes' => [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'heic' => 'image/heic',
        'pdf' => 'application/pdf',
    ],

    'categories' => ['lab_test', 'imaging', 'prescription', 'medical_letter', 'other'],

    /** A patient's deleted report keeps its file this many days, then the daily job purges it. */
    'purge_after_days' => (int) env('MEDICAL_REPORTS_PURGE_AFTER_DAYS', 30),

    /** File streams allowed per user per minute. */
    'streams_per_minute' => (int) env('MEDICAL_REPORTS_STREAMS_PER_MINUTE', 30),

    /**
     * The consent a patient gives before their FIRST upload. PENDING LEGAL REVIEW.
     * Bump `version` when the text changes: every patient is asked again before
     * their next upload. Stored in patient_consents with type `medical_reports`.
     */
    'consent' => [
        'version' => 'reports-1',
        'text_ar' => 'أوافق على رفع تقاريري الطبية إلى التطبيق ليطّلع عليها أخصائي التغذية المتابع لحالتي فقط. تُحفظ الملفات مشفّرة، ويُسجَّل كل اطلاع عليها، ويمكنني حذفها في أي وقت.',
    ],

];
