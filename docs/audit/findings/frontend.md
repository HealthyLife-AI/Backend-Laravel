# Frontend audit (agent `frontend`, prefix FE-) — HealthyLife dashboard (Next 16)

Setup: `next build` + `next start -p 3000` from /home/user/Frontend-Nextjs with LARAVEL_API_URL / NEXT_PUBLIC_LARAVEL_API_URL=http://127.0.0.1:8000/api/v1,
NEXT_PUBLIC_PATIENT_APP_URL=https://play.google.com/store/apps/details?id=example.audit (fake), SUPPORT_EMAIL=support@example.test (fake), no Sentry DSN, no Google client id.
Accounts created through the UI only (nutritionist fe.audit.<n>@example.test). Playwright scripts: docs/audit/evidence/frontend/*.js (lib.js = console/network recorder).

## J1 New nutritionist (dashboard part)
Measured path (AR): landing "ابدأ مجانًا" -> /ar/register (4 fields, no terms checkbox, no email verification) -> lands on /ar/dashboard logged in
-> hero "إضافة مريض" opens dialog -> name + phone (+970 default) -> submit -> credentials + WhatsApp button. **10 interactions total, 4 from dashboard to "first patient added".**
Username is auto-generated (pt101.jaw8) when left blank; Arabic-Indic digits in phone (٠٥٩٩...) are converted correctly (internationalPhone, lib/clients/credentials.ts:35).
wa.me text (AR, follows the DASHBOARD locale, not the patient's): greeting with patient name, username, **plain password**, app URL (only if NEXT_PUBLIC_PATIENT_APP_URL set), "change password in app" hint. No nutritionist/clinic name.

### FE-01 Sign-up promises "terms of service" that do not exist; no consent capture
- Severity: P1 · Repo: Frontend-Nextjs · Location: src/components/auth/RegisterForm.tsx:137, src/messages/en.json:38, src/components/home/HomeFooter.tsx:14,54
- Reproduction & evidence: j1.js -> j1-02-register-ar.png: "بمتابعتك فأنت توافق على شروط الخدمة وسياسة الخصوصية" as plain text, no link, no checkbox. No /terms route exists (build route list); footer renders "Terms & conditions" as plain text with comment "no page exists yet".
- Impact: public sign-up binds nutritionists (who upload third parties' health data) to terms that do not exist; no record of acceptance. Legal blocker for public sign-up.
- Proposed fix: publish terms page (+ DPA language for clinics), link both docs from the form, store accepted version server-side. · Fix size: M

### FE-02 Nutritionist sign-up has no email verification (and no bot friction)
- Severity: P1 · Repo: Backend-Laravel + Frontend · Location: app/Http/Controllers/Api/Auth/AuthController.php:49-60; RegisterForm.tsx:50 (router.push("/dashboard"))
- Reproduction & evidence: j1.js: registered fe.audit.602783@example.test (never verified) and landed logged in on /ar/dashboard; added a patient and requested an AI draft immediately. Register only does User::create + assignRole.
- Impact: anyone can register with someone else's email, create patient accounts and burn the shared Groq budget (≈1 draft/min platform-wide) with scripts; nothing ties an account to a reachable person.
- Proposed fix: email verification (or at least gate AI draft/patient creation until verified) + throttle/captcha on /auth/register. · Fix size: M

### FE-03 First-run dashboard tells a brand-new nutritionist "Welcome back" and "All your patients are on track today" with zero patients
- Severity: P2 · Repo: Frontend-Nextjs · Location: dashboard page hero (src/app/[locale]/(dashboard)/dashboard/page.tsx, messages "welcomeBack"/all-on-track keys)
- Reproduction & evidence: j1-03-first-run-ar.png: "مرحباً بعودتك" + "كل مرضاك على المسار الصحيح اليوم." + nine 0 tiles; the only next step is the hero "إضافة مريض" button, no onboarding checklist.
- Impact: misleading empty state at the activation moment; nine zero tiles add noise.
- Proposed fix: zero-patient variant: "Welcome", one card "Add your first patient" (+ what happens next: credentials over WhatsApp, health profile, plan); hide tiles until ≥1 patient. · Fix size: S

### FE-04 A just-created patient is shown as "stopped logging" (توقّف عن التسجيل)
- Severity: P2 · Repo: Frontend-Nextjs (status from API adherence) · Location: patient page header + AdherenceHeadline (src/components/progress/AdherenceHeadline.tsx)
- Reproduction & evidence: explore.js -> j3-01-patient-ar.png/.txt: patient created seconds earlier, status "لم يسجّل الدخول بعد" AND adherence "توقّف عن التسجيل" twice.
- Impact: false alarm on every new patient; nutritionist cannot tell "never started" from "dropped out".
- Proposed fix: "not started yet" state when the patient has never logged / has no active plan. · Fix size: S

### FE-05 Arabic-Indic digits typed into numeric fields are silently dropped
- Severity: P2 · Repo: Frontend-Nextjs · Location: src/components/clients/HealthProfileForm.tsx:150-178 (type="number" inputs; same pattern in body-composition, plan kcal, measurement schedule, food quantity)
- Reproduction & evidence: j3a.js: keyboard.type('٨٢٫٥') into weight -> value "" ; Playwright fill('٨٢') -> "Cannot type text into input[type=number]". Screenshot sweep-01-weight-arabic-digits-ar.png. Phone field (type=tel) IS converted correctly (internationalPhone).
- Impact: a nutritionist on an Arabic keyboard (mobile/tablet) sees the field stay empty with no message; form uses noValidate so the save fails with the API's generic error.
- Proposed fix: type="text" inputMode="decimal" + normalise ٠-٩/۰-۹ and "٫" before Number(), as credentials.ts already does. · Fix size: S

### FE-06 Body-composition save failure is silently ignored
- Severity: P3 · Repo: Frontend-Nextjs · Location: src/components/clients/HealthProfileForm.tsx:116-131 (await addBodyCompositionReading(...) result unchecked, then "saved")
- Reproduction & evidence: by inspection.
- Impact: measurements can be lost while UI says "saved".
- Proposed fix: check result, show error. · Fix size: S

### FE-07 AI-draft fallback is labelled honestly, but its content is not a usable plan
- Severity: P2 · Repo: Backend-Laravel (fallback generator) / Frontend display · Location: POST /clients/{id}/meal-plans/ai-draft (is_ai_fallback:true)
- Reproduction & evidence: j3c.js -> j3-06-ai-draft-result-ar.png: banner "لم يتوفّر اقتراح الذكاء الاصطناعي هذه المرة، فاقترحنا مسودة تعتمد على السعرات فقط. الحساسيات فُحصت بالاسم فقط" + retry button (good). Content (j3-draft-text.txt): breakfast = "أرز بسمتي مطبوخ (273.6 جم)", morning snack = "أرز أبيض مطبوخ (101.8 جم)" with alternatives "كبسة دجاج"/"مجدرة", lunch "مجدرة (308.9 جم)"; carbs 68% of kcal; one food per meal; gram weights with decimals. Took 5.6 s.
- Impact: with Groq saturated (one draft/min platform-wide) most drafts at launch will be this; nutritionist must rebuild every meal -> J3 friction and a trust hit.
- Proposed fix: meal-slot-aware fallback (breakfast/snack food pools), round to household portions/5 g. · Fix size: M

### FE-08 Patient page fires a 404 to /clients/{id}/reports on every view (feature flag off)
- Severity: P3 · Repo: Frontend-Nextjs · Location: src/components/reports/ReportsCard.tsx; route group behind EnsureMedicalReportsEnabled (routes/api.php:191)
- Reproduction & evidence: explore.js j3-01-patient-ar-console.json: "GET :8000/api/v1/clients/3/reports 404".
- Impact: console error/Sentry noise on every patient page; medical-report download could not be exercised locally (feature off).
- Proposed fix: expose the flag in /auth/me or a features endpoint and skip the request. · Fix size: S

### FE-09 Every public page (landing, login, register) logs a 401 console error from silent refresh
- Severity: P3 · Repo: Frontend-Nextjs · Location: src/components/auth/AuthProvider.tsx (POST /api/auth/refresh on mount without cookie)
- Reproduction & evidence: j1a-console.json: "POST /api/auth/refresh 401" on /ar and /ar/register.
- Impact: console noise, wasted request on the marketing page; would show in Sentry if fetch errors are captured.
- Proposed fix: return 204 when no cookie, or skip refresh on public routes. · Fix size: S

### FE-10 Numbers not grouped per design rule (kcal "1824 سعرة/يوم", "1324")
- Severity: P3 · Repo: Frontend-Nextjs · Location: messages ar.json:282,1897 "{value} سعرة/يوم" with raw value; HealthProfileForm.tsx:207; PlanDesigner kcal lines
- Reproduction & evidence: j3a output "1824 سعرة/يوم"; j3-plan-page.txt "اقتراح النظام: 1324".
- Impact: violates design-system-rules §0 addendum 3 (minor).
- Proposed fix: Intl.NumberFormat per rule. · Fix size: S

### FE-11 WhatsApp credential message: locale follows the dashboard, no sender identity, password lands in browser history
- Severity: P3 · Repo: Frontend-Nextjs · Location: src/components/clients/CredentialsCard.tsx:33-45,70; src/lib/whatsapp.ts
- Reproduction & evidence: j1b.js decoded href: "مرحبًا <name>، أنشأ لك أخصائيك حسابًا…/ اسم المستخدم / كلمة المرور: <plain> / حمّل التطبيق: <url> / …". App line silently dropped if NEXT_PUBLIC_PATIENT_APP_URL unset. Button colour is raw hex bg-[#25D366] (design-rule violation).
- Impact: patient receives a password from an unknown number with no clinic/nutritionist name (phishing-like); wa.me URL with plain password stays in history of shared clinic PCs (target=_blank).
- Proposed fix: include nutritionist name; consider one-time activation code instead of password; ensure PATIENT_APP_URL is set in prod. · Fix size: S
