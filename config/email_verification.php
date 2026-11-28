<?php

/*
|--------------------------------------------------------------------------
| Nutritionist e-mail verification (audit BE-17)
|--------------------------------------------------------------------------
|
| Nutritionists who sign up themselves get a verification e-mail. Until the
| address is verified they cannot add patients or use the AI endpoints
| (HTTP 403, code `email_unverified`).
|
| ROLLOUT. `required_from` is a date (YYYY-MM-DD, clinic timezone). Only
| nutritionists created ON OR AFTER it are held to the rule; everyone created
| before is grandfathered, because the pilot nutritionists registered before
| any verification existed and have no verified address. Empty (the default)
| enforces nothing for anybody: the e-mails still go out and /auth/me reports
| the status, so the dashboard page can be live first.
|
|   dokku config:set <app> EMAIL_VERIFICATION_REQUIRED_FROM=2026-12-10
|
| Google sign-in marks the address verified itself.
*/

return [
    'required_from' => env('EMAIL_VERIFICATION_REQUIRED_FROM') ?: null,

    // How long a link in the e-mail stays valid.
    'link_hours' => (int) env('EMAIL_VERIFICATION_LINK_HOURS', 48),
];
