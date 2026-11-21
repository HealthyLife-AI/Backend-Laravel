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

### FE-12 Whole milk goes into an ACTIVE plan of a patient with a CONFIRMED milk allergy with no warning, no dialog, no acknowledgement
- Severity: P0 (patient safety) · Repo: Backend-Laravel (AllergenConflictService) + Frontend (how "unverified" is shown) · Location: app/Services/MealPlans/AllergenConflictService.php:129-160 (tag match uses `$food->allergens ?? []`; NULL = no match), :113 (NULL only lands in `unverified_items`); src/components/mealPlans/FoodAutocomplete.tsx:200-227 (grey "?" note only)
- Reproduction & evidence: j3b.js added allergy "الحليب ومشتقاته (اللاكتوز) / حساسية مؤكدة" via UI (j3-03-allergy-added-ar.png). j3d/j3e.js: search "حليب" -> j3-07-milk-search-ar.png shows "حليب كامل الدسم", "حليب خالي الدسم", "حليب بالشوكولاتة" each with only grey "لم يتم التحقق من مسببات الحساسية لهذا الصنف"; selected and added whole milk to breakfast, "حفظ الخطة" -> "تم حفظ الخطة." (j3-09b-after-save-ar.png), no dialog. API: GET /clients/3/meal-plans -> plan 5 status "active", foods include "حليب كامل الدسم", allergen_conflicts [], allergen_ack_at null, unverified_items [{food_id 3812}]. POST allergen-check {food_ids:[3812,3816,3426]} -> conflict null for all. Earlier activation of the same plan also produced no dialog (j3d: 200 POST /meal-plans/5/activate).
  Catalog (read-only tinker on healthylife_ai): 7,851 foods, **7,793 (99.3%) have allergens NULL**; 560 untagged foods are named milk/حليب/cheese. Group allergies (milk, eggs, nuts, wheat, sesame, fish…) therefore match almost nothing; only "other" free-text allergies get a name match.
- Impact: the core allergen safety net is effectively off for the 10 structured groups; a nutritionist trusting the red-shield UI ships milk to a confirmed-milk-allergic patient. Saving an already-active plan pushes it straight to the patient.
- Proposed fix: (1) treat untagged foods as name-matched against per-group keyword lists (حليب/لبن/جبن/milk/cheese/whey…; بيض/egg; قمح/خبز/wheat/flour…) and report as "possible conflict" requiring ack for confirmed allergies; (2) in the UI show an amber "may contain <group> — not verified" on untagged foods when the patient has any allergy; (3) tag the catalog (bulk keyword pass + admin review). · Fix size: M (1+2), L (3)

### FE-13 Editing an ACTIVE plan publishes a confirmed-allergy food to the patient before any acknowledgement
- Severity: P1 (patient safety) · Repo: Backend-Laravel + Frontend · Location: app/Http/Controllers/Api/MealPlans/MealPlanController.php:108-121 (save always succeeds, notifyPatient 'plan_updated'); src/components/mealPlans/PlanDesigner.tsx:521-530 (red banner after the fact)
- Reproduction & evidence: j3f.js on active plan 5: added "فتة حمص" (tagged milk_lactose; red "يحتوي على: الحليب… — حساسية مؤكدة" shown inline, j3-11-tagged-selected-ar.png), clicked "حفظ الخطة": green "تم حفظ الخطة." AND red "هذه الخطة تحتوي الآن على صنف يتعارض مع حساسية مؤكدة لم تتم مراجعتها" with "مراجعة" (j3-13-tagged-after-save-ar.png). API: status "active", allergen_ack_at null, new_unacknowledged_conflicts true, conflict فتة حمص/confirmed_allergy. The activation-time ack gate is bypassed by "activate clean plan, then edit".
- Impact: the patient app gets the plan_updated push and the allergenic food before the nutritionist acknowledged anything; the ack becomes a retroactive note.
- Proposed fix: for active plans, refuse (422 allergen_conflict_unacknowledged) a save that adds an uncovered confirmed-allergy conflict unless `acknowledge_allergens=true` is sent; dashboard reuses the activation dialog before saving. Additive request field, old app unaffected. · Fix size: S-M

### FE-14 A nutritionist cannot delete their own account (no UI, no API)
- Severity: P1 (legal, public sign-up) · Repo: Backend-Laravel + Frontend · Location: routes/api.php:360-364 (DELETE me/account is the patient's BR-18 route); no delete control in src/components/settings/*, no deletion call in src/lib/**
- Reproduction & evidence: grep of src/ for deleteAccount/me/account -> none; settings page buttons (explore) have no delete action.
- Impact: self-registered professionals have no way to erase their account/data; privacy policy §rights promises deletion "from the app's settings" (patient-only wording).
- Proposed fix: settings "Delete account" for nutritionists with a defined fate for their patients (export/transfer/delete), plus API. · Fix size: M

### FE-15 Public patient deletion page relies on optional SUPPORT_EMAIL for the web route
- Severity: P2 (Play policy) · Repo: Frontend-Nextjs · Location: src/app/[locale]/account-deletion/page.tsx; .env.example SUPPORT_EMAIL ("Leave blank to hide the contact line")
- Reproduction & evidence: curl /en/account-deletion: in-app steps + "If your follow-up has ended… the app no longer opens… Ask your nutritionist… or contact support: <SUPPORT_EMAIL>". With SUPPORT_EMAIL unset the only out-of-app path disappears.
- Impact: Play's data-deletion requirement needs a web way to request deletion; a patient whose follow-up ended (app locked) depends on that line.
- Proposed fix: make SUPPORT_EMAIL required in prod (fail build/start if empty) or add a web request form. · Fix size: S

## I4 / J4 sweep

### FE-16 Normal browsing hits the 120 req/min API limit; 429s render as "nothing here" empty states
- Severity: P1 · Repo: Frontend-Nextjs + Backend-Laravel · Location: app/Providers/AppServiceProvider.php:113-117 (api limiter 120/min by user id, else IP); dashboard pages fan out (reqcount.js); alerts page empty state src/app/[locale]/(dashboard)/dashboard/alerts/page.tsx
- Reproduction & evidence: reqcount.js: one load of /ar/dashboard = 11 API requests, /patients = 8, /patients/3 = 26, /patients/3/plan = 14 (plus /api/auth/refresh on each hard load). sweep.js (one user, ~15 page loads/min) -> on /en/dashboard/alerts: "http429 GET /api/v1/alerts?is_read=0", "/clients", "/quick-requests", "/me/nutritionist-profile", and GET :3000/api/auth/me 429. Screenshot i4-empty-en_alerts.png: tiles show "–" and the empty state "Alerts appear here once the daily rule engine finds something…" — a rate-limit error presented as "no alerts".
- Impact: a nutritionist going through 5+ patients a minute gets throttled and is told there are no alerts/requests/patients; important alerts can be missed. Unauthenticated BFF calls (refresh/login from the Next server) are keyed by the server's IP, so in production all nutritionists may share one bucket (Unverified, see list).
- Proposed fix: show an error state (not empty state) on any non-2xx; batch the patient page (one summary endpoint) or raise the per-user limit for nutritionists; key BFF refresh by token hash / forward client IP. · Fix size: M

### FE-17 Appointments: availability is entered in one platform zone (Asia/Riyadh) but shown in the browser's zone, unlabeled — Gaza clinics are off by one hour
- Severity: P1 · Repo: Backend-Laravel + Frontend · Location: config/scheduling.php:21 (SCHEDULE_TIMEZONE default 'Asia/Riyadh', "PLACEHOLDER"); src/components/appointments/AvailabilitySettings.tsx:38 + messages hint "{tz} time"; PatientAppointmentCard.tsx:64 and appointments page format without timeZone; BookAppointmentDialog.tsx:103 / RescheduleDialog.tsx:46 format WITH slots.timezone (dayFmt at :102/:45 without it)
- Reproduction & evidence: PUT /me/availability windows 10:00-12:00 -> response timezone "Asia/Riyadh" (settings shows literal "Asia/Riyadh"/"Riyadh" in the Arabic UI). Booked 2026-11-22T10:00:00+03:00. j4-tz.js with browser timezoneId Asia/Gaza: patient card "الأحد، 22 نوفمبر في 09:00 ص", appointments page "09:00 ص–09:30 ص" (j4-02/j4-03 PNGs) — no zone label. Booking dialog shows the same slot as 10:00 (slots.timezone).
- Impact: a Gaza nutritionist (UTC+2 winter) sets "10:00-12:00", the booking dialog says 10:00, the list and patient card say 09:00; patients in Gaza see a different hour than the clinic intended. Yemen (UTC+3) coincidentally fine. Per-clinic zones do not exist.
- Proposed fix: per-nutritionist timezone (default from browser at sign-up, editable in settings), format every appointment time in that zone with a visible zone label; localise the zone name. · Fix size: M

### FE-18 English pages show Arabic-only catalog text (conditions, allergy groups, instruction suggestions)
- Severity: P2 · Repo: Backend-Laravel (catalog labels) / Frontend · Location: /en/dashboard/patients/{id}/health-profile (conditions list, allergy labels), /en/.../plan (instruction suggestion chips)
- Reproduction & evidence: sweep.js data run, i4-data-report.json: /en health-profile shows "سكري النوع الأول / سكري النوع الثاني / … / الداء البطني (السيلياك) / احتباس السوائل", "الحليب ومشتقاته (اللاكتوز)"; /en plan shows "حافظ على هدوئك وخصص وقتًا يوميًا لتمارين التنفس". Screens i4-data-en_patients_3_health-profile.png, i4-data-en_patients_3_plan.png.
- Impact: English-locale nutritionists cannot read core clinical choices; violates the two-locale rule.
- Proposed fix: API returns label_en (or keys) and the dashboard picks by locale. · Fix size: S-M

### FE-19 (info for cross-app) Values the dashboard computes itself instead of taking from the API
- Severity: P3 (consistency risk) · Repo: Frontend-Nextjs · Location:
  - Plan kcal/protein/carbs/fat per item, meal, day: src/components/mealPlans/mealSlots.ts:108-131 (itemMacros = per_100g × grams/100, round1; mealMacros/planMacros sum), fiber :127-131
  - % of calorie target, gap, macro calorie shares: src/components/mealPlans/DailySummaryPanel.tsx:28-43,108-110
  - Printed plan totals: src/components/mealPlans/PlanBreakdown.tsx:33,103 (same client math)
  - BMI and BMI class shown on patient/plan header ("محسوب"): src/lib/clients/bmi.ts:12,24 via BmiSummary.tsx:35 (API also returns calorie_suggestion.bmi / bmi_class)
  - Chart peaks/rounding only: PlanVsActualChart.tsx:48,175-178
  Adherence %, weight change, daily calorie needs, suggested kcal, counts on the home tiles come from the API (AdherenceHeadline reads adherence_percent/reference_percent; HealthProfileForm shows daily_calorie_needs from the response).
- Reproduction & evidence: by inspection + j3-draft-text.txt (client totals 1324 = approved 1324).
- Impact: the patient app must use the same per-100 g × grams rounding, or dashboard and app disagree on plan kcal/macros.
- Proposed fix: have the API return per-meal/day totals in MealPlanResource and render those. · Fix size: M

Fake/sample data grep (src/): no Lorem/mock/fake/Math.random in dashboard code (Math.random only in reactbits/StarBorder.tsx animation). Landing uses illustrative numbers: messages "stat1Value" "+7,800"/"7,800+ approved foods with USDA values" (DB has 7,851 foods), "58" local dishes, "144" ingredients, sample "94%" adherence, "48 مريضًا", "ملتزمة 92%", "88%" summaries in mockups (BentoVisuals.tsx:41-50,143-145). See FE-21.

## Sweep (frontend security / legal)

### FE-20 Next.js 16.3.4 has a critical advisory set; npm audit --omit=dev: 1 critical, 2 high, 5 moderate
- Severity: P1 · Repo: Frontend-Nextjs · Location: package.json ("next": "16.3.4", @sentry/nextjs ^11.4.0)
- Reproduction & evidence: `npm audit --omit=dev`: next 16.0.0-16.4.4 critical (RCE in next/og ImageResponse; use-cache draft-mode leak; SSG/ISR cache poisoning in self-hosted; cross-client response injection when forwarding fetched responses — GHSA-pc58-hwjq-p385), sharp <0.35.5 high, source-map-js high, @sentry/nextjs/node/bundler-plugins, glob, minimatch moderate. src/ does not use next/og or "use cache" (grep), so the RCE path is not reachable; the BFF route handlers do forward fetched responses (relevant to GHSA-pc58 if self-hosted; Vercel hosting reduces).
- Impact: known-vuln framework on a health-data dashboard.
- Proposed fix: bump next + eslint-config-next to 16.4.6+, `npm audit fix` for sharp/source-map-js, bump @sentry/nextjs. · Fix size: S

### FE-21 Login CSRF on the BFF: a cross-site text/plain POST logs the victim into an attacker account
- Severity: P2 · Repo: Frontend-Nextjs · Location: src/app/api/auth/login/route.ts:13-14 (request.json() with no Content-Type/Origin check); same in register/google/forgot/reset routes
- Reproduction & evidence: `curl -X POST :3000/api/auth/login -H 'Origin: https://evil.example' -H 'Content-Type: text/plain' --data '{"email":…,"password":…,"x":"="}'` -> `HTTP/1.1 200 OK` + `set-cookie: hl_refresh=<MASKED>; … Secure; HttpOnly; SameSite=lax` (an HTML form with enctype=text/plain can send this body cross-site as a top-level navigation, where Lax cookies are set).
- Impact: a nutritionist silently signed into an attacker-controlled account may add real patients (names, phones, health data) into it. Cookie flags themselves are fine (HttpOnly, Secure in prod, SameSite=Lax, 30 days); refresh/logout are protected by Lax.
- Proposed fix: reject non-`application/json` and mismatched Origin in all /api/auth/* POST handlers. · Fix size: S

### FE-22 No security headers: no CSP, no frame-ancestors / X-Frame-Options, X-Powered-By exposed
- Severity: P2 · Repo: Frontend-Nextjs · Location: next.config.ts (empty config, no headers())
- Reproduction & evidence: `curl -sI :3000/ar` -> only `X-Powered-By: Next.js`; no Content-Security-Policy, X-Frame-Options, Referrer-Policy, Permissions-Policy, X-Content-Type-Options.
- Impact: dashboard can be framed (clickjacking of "reset password" / "delete patient" / "send on WhatsApp"); no XSS containment while an access token lives in JS memory; Referer may carry patient IDs to third parties (wa.me, Google).
- Proposed fix: headers() with frame-ancestors 'none', CSP (self + API origin + accounts.google.com + Sentry ingest), Referrer-Policy strict-origin-when-cross-origin, nosniff, poweredByHeader:false. · Fix size: S

### FE-23 Landing/clinical-checks copy overstates the allergen check
- Severity: P1 (consumer-protection / patient safety, tied to FE-12) · Repo: Frontend-Nextjs · Location: src/messages/en.json:734-737 (+ ar equivalents) "Allergies, glycemic load, sodium and calories are checked on every food and alternative you add", "Each food and alternative is checked against the client's approved allergies"; FAQ/aiDraftHint "from the client's … allergies"
- Reproduction & evidence: FE-12 run: whole milk passes a confirmed milk allergy unflagged; 99.3% of foods untagged.
- Impact: marketing promise a clinic relies on is false for the catalog as shipped; liability if a reaction occurs.
- Proposed fix: fix FE-12 first or reword ("foods tagged with allergens are checked; untagged foods are marked unverified"). · Fix size: S
- Other landing numbers: "+7,800 / 7,800+ approved foods with USDA values" (DB 7,851 total incl. 58 local dishes; "approved … USDA" is roughly backed), "58" dishes, "144" ingredients — backed by catalog counts only by inspection; testimonial-style sample stats ("94%" adherence, "48 مريضًا", "ملتزمة 92%", "88%") appear inside illustrated mockups (BentoVisuals.tsx:41-50,143-145, messages ~:116,596,691,719,787) — low risk if visibly illustrative; no headcount/customer claims found.

### FE-24 Privacy policy vs. web client: Sentry browser events can carry Arabic text/patient names; complaint contact is a personal Gmail
- Severity: P2 · Repo: Frontend-Nextjs · Location: src/lib/sentry.ts:28-41 (scrub deletes request data/headers/user and query strings, filters breadcrumb data keys, but keeps event.message, exception values and breadcrumb `message` e.g. console breadcrumbs); content/legal/privacy.en.md:85 ("emails, phone numbers, Arabic text, request contents and user details are removed before sending"), :129 (complaints to a personal gmail.com address), :82 (Vercel "nothing stored there")
- Reproduction & evidence: by inspection (no DSN configured locally, so not sent). sendDefaultPii false, tracesSampleRate 0, no Replay integration (no session replay — good). DSN is NEXT_PUBLIC (normal; not set in this build, bundle had no DSN).
- Impact: policy claim "Arabic text removed" is not implemented on the web side (it may be on the server/Laravel side); Vercel keeps request logs (URLs with patient IDs) — "nothing stored" is inaccurate; a personal Gmail as the data-protection contact looks unprofessional to Play reviewers/regulators.
- Proposed fix: scrub event.message/exception values/breadcrumb messages of Arabic/emails/phones (mirror backend rule) or reword; use an organisational privacy@ address; reword Vercel row. · Fix size: S

### FE-25 Route protection: proxy only checks cookie presence (OK as UX gate); data is API-enforced
- Severity: info · Location: src/proxy.ts:25-44
- Reproduction & evidence: by inspection + curl `/ar/dashboard/patients` without cookie -> redirect to /ar/login?next=… (see build "Proxy (Middleware)"). A forged `hl_refresh=x` cookie renders the dashboard shell (static, no data) and AuthProvider bounces after refresh 401. Data endpoints are on Laravel with Bearer tokens; NEXT_PUBLIC_LARAVEL_API_URL is in the client bundle by design (README) — note lib/auth/api.ts:3-5 comment "the browser never talks to Laravel directly" is stale.

## Secret scan (full history, all three repos)
Tool: gitleaks 8.21.2 (release tarball, /tmp/claude-0/tools/gitleaks) `gitleaks git <repo> --redact` + regex pass over `git log -p --all` (gsk_, Telegram \d+:AA…, Sentry DSN, AKIA, private_key_id, sk-, APP_KEY=base64, JWT_SECRET=) + committed-file names (.env, *.jks, *.keystore, key.properties, service-account, google-services.json, *.p12).
- Frontend-Nextjs: 0 gitleaks hits, 0 regex hits, no sensitive files ever committed.
- Backend-Laravel: 37 gitleaks hits, all non-secret on triage:
  - generic-api-key ×22 in API_CONTRACT.md, postman/build_collection.py, tests/Feature/Auth/GoogleAuthTest.php (commits 0f78f488, 161cdc00, 59ea2ea2): example `access_token`/`refresh_token`/`invite_token`/`idempotency_key` placeholders.
  - jwt ×10 in postman/HealthyLife-AI.postman_collection.json (161cdc00, 59ea2ea2): payload sub "4", no iss/exp/iat — example tokens; cannot prove which secret signed them -> if they were minted with a real JWT_SECRET they are non-expiring, so rotate JWT_SECRET once if unsure (Unverified).
  - private-key ×4 tests/Feature/Notifications/FcmPushServiceTest.php:32, SendLogRemindersTest.php:22-23: FIXTURE_PRIVATE_KEY test fixture (documented throwaway), not a Firebase service-account key.
  - curl-auth-header ×1 docs/audit/findings/mobile.md:81 (09c931b3): placeholder `Bearer <…>` in audit notes.
  - JWT_SECRET= regex ×3: .github/workflows/ci.yml (34c9d62) generates it with openssl at CI time; .env.example has it empty. No .env ever committed.
- Mobile-Flutter: 15 gitleaks hits, all `gcp-api-key` = Firebase client API keys in android/app/google-services.json (19802c5c, b9fcae53, bc7227b8) and lib/firebase_options.dart (same commits). Public-by-design identifiers (AIza…MASKED); risk depends on API-key restrictions in Google Cloud (Android package + SHA-1, API allowlist FCM/Installations) — NOT CHECKED (no console access). No key.properties / *.jks / service-account JSON ever committed.
Result: no real secret found in any history.

## J4 / J5 (dashboard side)

### FE-26 Ending follow-up leaves the patient's upcoming appointment booked
- Severity: P2 · Repo: Backend-Laravel (archive) + Frontend · Location: src/components/clients/FollowUpControls.tsx:26-29 (archiveClient), patient header "Next appointment"
- Reproduction & evidence: j5b.js: Settings tab -> "End follow-up" -> "Yes, end follow-up" (j5-01/j5-02 PNGs): page says "Follow-up ended on 21 November 2026 … the patient can't sign in to the app" and still shows "Next appointment: Sunday 22 November at 07:00 · Follow-up · WhatsApp" (07:00 = UTC browser, same slot shown 09:00 in a Gaza browser and booked as 10:00 Riyadh — FE-17).
- Impact: a slot stays blocked/expected for a patient who can no longer open the app to see or cancel it.
- Proposed fix: cancel (or ask to cancel) future appointments when follow-up ends; notify patient by the existing channel. · Fix size: S

### FE-27 English UI mixes "clients" and "patients"
- Severity: P3 · Location: src/messages/en.json (clients.* keys: "Add client", "Total clients", "Manage and track your clients' health status…") on the page titled "Patients"
- Reproduction & evidence: j5b.js list text "Patients / Manage and track your clients'… / Add client / 1 / Total clients".
- Proposed fix: one term. · Fix size: S

Checked and OK: EN WhatsApp text ("Hi <name>, your nutritionist created a HealthyLife account… Username… Password… Get the app… Account → Password"); ar/en message files have identical key sets (0 missing either way); phone viewport 390px: /ar/dashboard and /ar/.../plan have no horizontal scroll (scrollWidth 390; phone-01/phone-02 PNGs); weekly AI summary empty state is honest ("first summary after the first full week, Monday morning"); proxy redirects unauthenticated /ar/dashboard/* to /ar/login?next=… (307).

## Flow friction (ranked by impact on "nutritionist adds a first patient")
J1 (activation): 10 interactions from landing to credentials; 4 from dashboard.
1. First-run dashboard is nine zero tiles + "welcome back / all on track" (j1-03-first-run-ar.png) — 0 extra clicks but no guidance; fix: zero-patient onboarding card (FE-03). Highest impact: it is the screen right after sign-up.
2. "Complete your profile" banner (gender + WhatsApp) competes with "Add patient" on every page (j1-03) — a second CTA at the activation moment; fix: show it after the first patient is added.
3. After credentials, the next step "Fill health profile" opens a long form whose numeric fields reject Arabic-Indic digits silently (FE-05) — costs a failed save + retype; fix: inputMode=decimal + digit normalisation.
J3 (plan creation):
1. Fallback draft (the common case under the shared Groq budget) = rice for breakfast and snacks (j3-06) — nutritionist rebuilds 5 meals ≈ 15-25 extra clicks; fix: slot-aware fallback (FE-07).
2. Adding a food: "إضافة طعام لوجبة …" -> search -> click result adds 100 g immediately; then edit grams — 3-4 clicks per item; decimals like 273.6 g from the draft need manual rounding; fix: round to 5 g / household units.
3. Allergen state is three different looks (red shield = tagged conflict, grey "?" = unverified, nothing = safe) and on an active plan the red banner appears only AFTER the save already reached the patient (FE-12/13); fix: amber "possible <group>" + confirm before save.
J4 (nutritionist side):
1. Times shown in browser zone without label vs booking in Asia/Riyadh (FE-17) — every Gaza appointment needs mental -1h.
2. Throttling under normal navigation shows empty states (FE-16) — nutritionist reloads/waits a minute.
3. Booking dialog from the patient page showed type/channel selects as "…" and no slots for a not-yet-active patient (j4-01-book-dialog-gaza-tz-ar.png) with no explanation; fix: say why no slots (patient pending/no availability) with a link to availability settings.
J5:
1. No nutritionist account deletion at all (FE-14).
2. Ending follow-up keeps appointments (FE-26).
3. Medical report download not reachable with the feature flag off and the patient page logs a 404 each view (FE-08).

## Coverage
- Verified (ran): J1 AR sign-up -> first run -> add patient -> credentials/WhatsApp (AR + EN); J3 AI draft fallback, allergy add, untagged + tagged allergen food, save/activate on active plan; J4 appointment timezone (API booking + UI display in Asia/Gaza/UTC browsers); J5 end follow-up, deletion page; I4 render of 11 dashboard pages (empty account) and 15 pages (data account) in AR+EN with console/network capture and wrong-script/ungrouped-number heuristics (i4-*-report.json, i4-*.png); fake-data grep; Arabic-Indic digits in weight + phone; npm audit; bundle scan; security headers; cookie flags; login CSRF; proxy gate; gitleaks + regex secret scan of all three repos.
- Read-only: Sentry wiring, privacy policy vs code, landing claims, client-side computations list, AllergenConflictService, update-plan controller.
- NOT CHECKED: J4 alerts list actions and quick-request replies (my accounts have no alerts/requests; the daily engine was not run and demo:seed was not used to avoid touching shared DB accounts); posts create/publish (posts/new only rendered, j5-03-post-new-en.png); medical report download (feature flag off locally); weekly summary with real data; admin pages (/dashboard/admin/*, no admin account); email-failure path for password reset; Arabic-Indic digits in every other numeric input (body composition, kcal, measurement schedule, food grams — same type=number pattern, expected same result); bidi of all dates/units (spot-checked plan + appointments only); Firebase API-key restrictions; nutritionist Google sign-in (no client id).
- Note: the first AR credentials screenshot showed a demo patient's generated password and was deleted; j1-08-credentials-en-masked.png masks it.

## Unverified
1. Unauthenticated BFF calls (login/refresh from the Next server) are throttled by the server's IP in production (one shared 120/min bucket for all nutritionists on Vercel) — inferred from AppServiceProvider.php:117 `$user?->id ?: $request->ip()`.
2. Postman example JWTs (sub 4, no exp) may have been signed with a real JWT_SECRET; if so they never expire.
3. Production food catalog has the same 99.3% untagged ratio as the local demo DB (local count 7,851 matches the landing claim, suggesting the same seed).
4. Patient app shows appointment times in the phone's zone (same mismatch as the dashboard list).
