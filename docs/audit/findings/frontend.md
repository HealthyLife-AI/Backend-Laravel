# Frontend audit (agent `frontend`, prefix FE-) — HealthyLife dashboard (Next 16)

Setup: `next build` + `next start -p 3000` from /home/user/Frontend-Nextjs with LARAVEL_API_URL / NEXT_PUBLIC_LARAVEL_API_URL=http://127.0.0.1:8000/api/v1,
NEXT_PUBLIC_PATIENT_APP_URL=https://play.google.com/store/apps/details?id=example.audit (fake), SUPPORT_EMAIL=support@example.test (fake), no Sentry DSN, no Google client id.
Accounts created through the UI only (nutritionist fe.audit.<n>@example.test). Playwright scripts: docs/audit/evidence/frontend/*.js (lib.js = console/network recorder).

## J1 New nutritionist (dashboard part)
Measured path (AR): landing "ابدأ مجانًا" -> /ar/register (4 fields, no terms checkbox, no email verification) -> lands on /ar/dashboard logged in
-> hero "إضافة مريض" opens dialog -> name + phone (+970 default) -> submit -> credentials + WhatsApp button. **10 interactions total, 4 from dashboard to "first patient added".**
Username is auto-generated (pt101.jaw8) when left blank; Arabic-Indic digits in phone (٠٥٩٩...) are converted correctly (internationalPhone, lib/clients/credentials.ts:35).
wa.me text (AR, follows the DASHBOARD locale, not the patient's): greeting with patient name, username, **plain password**, app URL (only if NEXT_PUBLIC_PATIENT_APP_URL set), "change password in app" hint. No nutritionist/clinic name.
