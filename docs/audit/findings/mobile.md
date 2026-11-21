# Mobile (Flutter patient app) audit findings — agent `mobile`

Repo: /home/user/Mobile-Flutter, main @ 4679fa1 (audit files on branch claude/magical-pasteur-1uwou4).
Local API http://127.0.0.1:8000/api/v1, accounts created through public register + nutritionist API only.

## I9 Installed apps keep working + contract check

Harness (reusable for any backend branch):
- `docs/audit/evidence/mobile/capture_fixtures.py` — registers a fresh nutritionist, creates patients via `POST /clients`,
  sets up plan/review/availability/schedule/check-in/post, then captures REAL JSON for every endpoint the app calls
  (73 fixtures, tokens/passwords redacted) into `Mobile-Flutter/test/audit/fixtures/`. Run: `python3 -I capture_fixtures.py --base <api>`.
- `Mobile-Flutter/test/audit/fixture_api.dart` — a Dio `HttpClientAdapter` serving the fixtures through the app's real
  `DioApiClient` (JSON decoding + error mapping), routed by `METHOD path`.
- `Mobile-Flutter/test/audit/contract_fixtures_test.dart` — wires `AppDependencies.inMemory(api: <fixture Dio client>)`
  and calls every repository read the app makes, for a rich account and an empty account (no plan → 204).

Result (`flutter test test/audit/contract_fixtures_test.dart`): 24 pass, 1 fails (MO-01). Every endpoint the app reads
parses on current backend; no field renamed/removed; no unrouted call. Static key diff (app-read JSON keys vs keys in
any real response) leaves only keys of features that had no data locally (goal projection/weight insight need more
readings; medical reports are off → 404) or local-storage keys — listed as NOT CHECKED below.

### MO-01 A meal log with fractional grams disappears from the app
- Severity: P3 · Repo: Mobile-Flutter · Location: lib/data/models/server_meal_log.dart:44 (`jsonInt(json['quantity_grams'])`)
- Reproduction & evidence: test `MO: a meal log with fractional grams is DROPPED by the app parser` in
  test/audit/contract_fixtures_test.dart → `Expected: not null, Actual: <null>`. Backend stores `meal_logs.quantity_grams`
  as decimal(6,1) and validates `numeric` (app/Http/Requests/Logs/StoreMealLogRequest.php:55), so 60.5 is accepted;
  `jsonInt(60.5)` is null and `fromJson` returns null → the log is skipped by `syncDay`.
- Impact: today only the app writes patient logs and it sends ints, so latent; any future client (web, import) writing
  a fractional quantity makes that log invisible on the phone. Related: plan quantities are fractional in real data
  (designer saved 60.5 g); the app rounds them (`meal_plan_dto.dart:99`, 60.5 → 61) and logs 61 g, so macros differ slightly.
- Proposed fix: read grams as double (round only for display) · Fix size: S

Notes (no finding): `policy_url` locally is the placeholder `https://your-dashboard-domain/ar/privacy` (env), check prod
value; server `me/today` reminder text is hard-coded feminine «أخصائيتك» regardless of nutritionist gender (P3, backend copy).
Push/inbox titles are generic («لديك ملاحظة جديدة من أخصائيك», «خطتك الغذائية جاهزة») — no health detail (fixture me_notifications).
Server error `message` strings are English; the app never shows them (follow_up_ended screen uses l10n) — verified by grep.
Existing hand-written fixtures: test/helpers/screen_fixtures.dart, sample_meal_plan.dart, meal_log_sync_test.dart and the
DTO tests use only keys present in real JSON (not stale); patient_features_parsing_test.dart uses keys of projection/
insight/reports shapes not exercised locally (unverified, not stale-proven).

## I2 Meal-log integrity (app side)

Live harness: `Mobile-Flutter/test/audit/meal_log_queue_live_test.dart` (real `MealLogRepositoryImpl` + `OfflineQueue` +
`SessionLocalDataSource` + `TokenRefreshCoordinator` + `createApiClient` Dio stack against the local API; skipped unless
`AUDIT_API` is set). Run output: `docs/audit/evidence/mobile/i2_live_run.txt`.

Verified OK (tests pass):
- Offline log survives an app restart (queue in SharedPreferences `pending_writes.v1`, removed only after the server
  answers, `offline_queue.dart:233-237`) and is sent once.
- Request reaches server, answer lost, replay → one server row: the idempotency key is generated once per food log at
  log time (`meal_log_repository_impl.dart:88,125`) and kept in the queued body; server answers 200 with the same id.
- Access token refused during a replay (tampered JWT) → 401 → one refresh → replay → 201.
- Double tap: the UI guards both log buttons (`meal_detail_screen.dart:100` `_logging`, `quantity_selector_screen.dart:682`
  `_saving`). The repository itself has no guard for `logFood` (two concurrent calls → 2 server rows, test output), and the
  use-case guard (`log_meal_use_case.dart`) only arms after the flush returns — fine while the UI guards hold.

### MO-02 Queued meals and measurements are deleted when the session ends by refresh failure (credential reset, token reuse, 30-day expiry)
- Severity: P0 (data loss) · Repo: Mobile-Flutter · Location: lib/core/network/token_refresh_coordinator.dart:77-80 →
  lib/data/datasources/session_local_data_source.dart:84-90 → lib/core/di/app_dependencies.dart:171,385-386 (offline
  queue is the first `PatientCache` forgotten); same path on `403 follow_up_ended` (lib/core/network/auth_interceptor.dart:60-61)
- Reproduction & evidence (`AUDIT_API=… flutter test test/audit/meal_log_queue_live_test.dart`, i2_live_run.txt):
  1. "nutritionist resets the password while a meal is queued": patient logs offline (queue=1); nutritionist uses
     `POST /clients/{id}/reset-password` (the documented forgot-password flow over WhatsApp; it ends every session);
     phone back online → `flush after reset: sent=0 halt=QueueHalt.unauthorized pending=0 events=(SessionEventType.expired)`;
     patient signs in with the new password → server has `0` logs (expected 1).
  2. "refresh answer lost, app killed, reopened after the 30 s grace": phone A's refresh reaches the server, answer lost;
     both of the patient's phones log one meal offline; A reopens 33 s later → old refresh token = reuse → server revokes
     every session → `A: pending=0 signedIn=false | B: pending=0 signedIn=false`; server logs `0` (expected 2).
- Impact: meals/weights a patient logged offline vanish silently (only "انتهت جلستك" is shown). Triggers are ordinary on
  launch: the patient forgets the password and the nutritionist resets it; a weak line drops a refresh answer and the
  app is closed before retrying within 30 s (also logs out every other device of that patient); a phone left offline/unused
  for 30 days (`JWT_REFRESH_TTL`). Adherence/alerts then show "not logging" for days the patient did log.
- Proposed fix: on session end keep `pending_writes` keyed by user id instead of deleting it; after the next sign-in of
  the SAME user id, flush it (idempotency keys make that safe); delete only on explicit sign-out with a confirm that shows
  the unsent count, or when a different user signs in. Optionally warn "N entries not sent yet" before sign-out. · Fix size: M

### MO-03 A malformed access token gives 500, which the app treats as "retry later" forever
- Severity: P2 · Repo: Backend-Laravel (JWT middleware) with app impact · Location: firebase/php-jwt DomainException
  surfaced by the jwt middleware (storage/logs/laravel.log: `Unexpected control character found … JWT.php:542`);
  app: lib/core/sync/sync_policy.dart:537-538 (5xx → `SyncWait`, never refresh)
- Reproduction & evidence: `curl -H 'Authorization: Bearer expired.or.garbage' …/auth/me` → `500 {"message":"Unexpected
  control character found","exception":"DomainException",…}` (stack trace shown because local APP_DEBUG); `Bearer garbage`
  or a tampered signature → 401. In the Dart live test a 3-segment garbage token made the queued POST answer 500 and the
  queue backed off instead of refreshing.
- Impact: a corrupted stored token (storage bug, truncated keystore write) blocks the queue and every screen with
  "server error" until reinstall/sign-out; on prod with APP_DEBUG=false it is still a 500 per request in the logs.
- Proposed fix: catch JWT decode exceptions in the middleware → 401 (backend, S). · Fix size: S

## I5 Days and time zones (app side)

Where the app decides "today": device-local everywhere — `core/time/date_only.dart:15` `isSameLocalDay`, used by
`meal_log_repository_impl.dart:74` (once-per-day planned meal), `meal_log_cubit.dart:37`, `daily_log_screen.dart:138,206,261`,
`weekly_plan_screen.dart:40,128`, `meal_plan_view.dart:43`, `meal_log_sync.dart:38,103`, `notifications_center_screen.dart:119`.
What it sends: meal log `logged_at` = `now.toUtc().toIso8601String()` → `…Z` (`meal_log_repository_impl.dart:87,124`);
measurement `recorded_at` = device-local date (`measurement_repository_impl.dart` `_body`); wellbeing PUT path = device date;
`/me/meal-logs` and `/me/progress` windows = device dates read by the server as clinic dates (sync fetches ±1 day to cover it).
Rendering: server timestamps → `toLocal()` (`json_read.dart` `jsonDateTime`), date-only strings kept as given.

Run (`TZ=Asia/Gaza|Asia/Aden AUDIT_API=… flutter test test/audit/meal_log_queue_live_test.dart --plain-name I5:`,
evidence `docs/audit/evidence/mobile/i5_tz_run.txt`), same instant 2026-11-20T21:30Z:
- Gaza (UTC+2 in November), 23:30 local: app shows the meal on **2026-11-20**; server (SCHEDULE_TIMEZONE=Asia/Riyadh) files it
  on **2026-11-21** → test fails.
- Aden (UTC+3), 00:30 local: app 2026-11-21, server 2026-11-21 → passes (Yemen = Riyadh offset all year).

### MO-04 Off-plan meals get the wrong meal type because the app sends `logged_at` in UTC ("Z"), not with the local offset
- Severity: P1 · Repo: Mobile-Flutter · Location: lib/data/repositories/meal_log_repository_impl.dart:124 (and :87)
- Reproduction & evidence: the app's body is `logged_at: 2026-11-21T17:33:12.698715Z` (Dio log in i2 run). Server infers an
  off-plan log's `meal_type` from the local time of `logged_at` using the sent offset (API_CONTRACT.md BR-16). Live:
  `2026-11-20T10:00:00.000Z -> breakfast` vs the same instant `2026-11-20T13:00:00+03:00 -> lunch`;
  `16:00:00.000Z -> lunch` vs `19:00:00+03:00 -> dinner`. The app sends no `meal_type` for off-plan foods.
- Impact: for every Gaza/Yemen patient, an off-plan food eaten 11:00–13:59 local is filed as breakfast, 17:00–19:59 as lunch,
  23:00–01:59 as dinner instead of snack (3 of every 24 hours shifted per boundary). The nutritionist's day view and any
  meal-type based rule show wrong meals. The code comment says "in UTC with an offset", the contract asks for the local offset.
- Proposed fix: send local time with its offset (e.g. format `now` with `timeZoneOffset` → `2026-11-20T13:00:00+03:00`), or send
  `meal_type` from the screen the food was logged from. Backend fallback: for `Z` timestamps infer with the patient's
  `timezone`/SCHEDULE_TIMEZONE instead of UTC. · Fix size: S

### MO-05 Gaza late-evening meals show on today in the app but count for tomorrow on the server
- Severity: P2 · Repo: Mobile-Flutter + Backend · Location: lib/core/time/date_only.dart:15 vs SCHEDULE_TIMEZONE day rule
  (API_CONTRACT.md Part C)
- Reproduction & evidence: i5_tz_run.txt — Gaza 23:30: `app shows on 2026-11-20 | server day=[2026-11-21]`.
- Impact: from late October to late March (Gaza UTC+2) every meal 23:00–23:59 Gaza time is on a different day for patient and
  nutritionist: the patient's "today" is complete, the dashboard/adherence/«لم يسجّلوا اليوم»/20:00 reminder count it tomorrow.
  Small window; Yemen unaffected. A patient travelling (other offset) gets larger gaps.
- Proposed fix: per-patient timezone on the server (the app already sends `timezone` with notification preferences,
  `notification_preferences_sync.dart:86`) and use it for the patient's day; or the app uses the server's `date` from
  `GET /me/today` as "today". · Fix size: M

## Play gates

- Release AAB: **NOT RUN**. No Android SDK in the container (`ANDROID_HOME` unset, no `sdkmanager`/`adb`), and
  `dl.google.com` is refused by the egress proxy (`403 … no rule or network setting allows this host`). Java present.
  CI PR HealthyLife-AI/Mobile-Flutter#41 adds the AAB job; APK/AAB `strings` scan therefore NOT CHECKED.
- targetSdk/compileSdk = `flutter.targetSdkVersion` / `compileSdkVersion` (android/app/build.gradle.kts:25,45) = **36**, minSdk 24
  (Flutter 3.41.8 `FlutterExtension.kt:23-34`). Play's current target-API rule (≥35 for new apps, from memory) — needs
  verification against Play docs; 36 satisfies it either way.
- Permissions (by inspection; merged manifest not built): app manifest INTERNET, RECEIVE_BOOT_COMPLETED; plugins add
  WAKE_LOCK, ACCESS_NETWORK_STATE, POST_NOTIFICATIONS (firebase_messaging 15.2.10), VIBRATE, POST_NOTIFICATIONS
  (flutter_local_notifications 22.3.2); image_picker/file_picker add none. Matches docs/store/data-safety.md (no location,
  contacts, ad id). Whether the Firebase AARs add `AD_ID`/`c2dm.RECEIVE` — needs the merged manifest (NOT CHECKED).
  `allowBackup=false` + data_extraction_rules present; inexact alarms only (no SCHEDULE_EXACT_ALARM).
- Health-apps declaration inputs (from data-safety.md + code): health info (weight, measurements, conditions, allergies,
  medications, sleep/stress/mood), medical documents (uploads, feature currently off on the local API: `/me/reports` 404),
  food logs; shared only with the patient's nutritionist; processors Google (FCM token, Crashlytics). Not a medical device;
  no Health Connect (FEATURE_HEALTH_SYNC off).
- In-app account deletion: exists and reachable — Profile → `DeleteAccountScreen` (presentation/screens/profile_screen.dart:427)
  → `DELETE /me/account {password}` (auth_remote_data_source.dart:58); public page `/ar|en/account-deletion` on the website.
- Store reviewer login: `php artisan demo:seed` (app/Console/Commands/Demo/SeedDemoData.php:170-175) takes passwords only from
  `DEMO_NUTRITIONIST_PASSWORD` / `DEMO_PATIENT_PASSWORD` (config/demo.php), else a random 14-char password printed once — no
  default in code. Demo-flagged accounts only (`--reset` deletes only flagged ones). OK.
- Crash reporting: Firebase Crashlytics only, `recordError(error, stack, printDetails:false)` (core/logging/crash_reporter.dart:43);
  grep finds no `setUserIdentifier`/`setCustomKey`/log calls; no Sentry. Firebase API keys in lib/firebase_options.dart:57,65
  (public client identifiers by design, masked here).
- Lock-screen text: every server push title/body is generic (lang/ar/notifications.php:7-22, e.g. «لديك ملاحظة جديدة من أخصائيك»,
  «لم تسجّل أي وجبة اليوم بعد»); FCM data holds ids only (API_CONTRACT). No weight/note/health detail on a locked phone. Copy uses
  feminine «أخصائيتك» in quick_request_answered/checkin_due/education_post regardless of the nutritionist (P3 copy).

## Force update (inputs for the lead's spec)

### MO-06 No minimum-version / force-update path, and the API cannot tell app versions apart
- Severity: P1 (store/launch: the first build stays on phones that never update) · Repo: Mobile-Flutter + Backend
- Reproduction & evidence: `grep -rni "min_version|minimum_version|force.update|X-App-Version|426"` over Backend app/config/routes
  and `grep "X-App-Version|User-Agent|min_version|forceUpdate|upgrade_required"` over lib/ → no hits. Dio sends only
  `Accept: application/json` (core/network/api_client_factory.dart:236); the default dart:io User-Agent carries no app version
  (the backend stores it in refresh_tokens.user_agent, RefreshTokenService.php:233).
- Inputs for a minimal design: version is available via `package_info_plus` already (core/config/app_version.dart, pubspec
  `version: 1.0.0+1`); one place to add a header is `createApiClient` BaseOptions headers (api_client_factory.dart:236) or a new
  interceptor next to AuthInterceptor; a global answer can be handled in `AuthInterceptor.onError` like `follow_up_ended`
  (auth_interceptor.dart:60) with a new SessionEventType routed in `app_router.dart:30-35`. This must ship in the FIRST store
  build, or old installs can never be told to update.
- Founder decision: the API host is baked in as a compile-time default `https://healthylife.apps.taqat.academy/api/v1`
  (lib/core/config/app_config.dart:15-17; website default the vercel.app domain, :26-28). Moving off the incubator domain later
  breaks every installed build unless the old host keeps proxying. Decide the long-term domain before the first upload.
- Proposed fix: send `X-App-Version`/`X-App-Build` on every request; backend `426 upgrade_required {store_url}` below a configured
  minimum; app shows a blocking update screen. · Fix size: M

## J2 Patient onboarding and first meal (Arabic first)

Flow (by code + committed goldens test/goldens/screens/*.png): login (username + generated password, from WhatsApp) →
`HealthDataConsentScreen` (consent_required, verified live: every `me/*` read is 403 until accepted) → `OnboardingMeasurementsScreen`
(weight is mandatory, continue disabled until chosen, :571) → `OnboardingHealthScreen` → `OnboardingNotificationsScreen` →
`PlanPreparingScreen` (fixed 5 s timer, plan_preparing_screen.dart:82) → home. On home a planned meal is ONE tap («أكلتها»,
02_home_360x800.png; also the + sheet 05_log_meal_sheet_360x800.png), works offline (queued).
ARB files: ar and en have the same keys, no English text in ar except the brand (`appTitle`, `aboutCopyright`).

### MO-07 Temporary password is never forced to change; only a reminder card
- Severity: P2 · Repo: Mobile-Flutter · Location: presentation/screens/home_dashboard_screen.dart:213, widgets/password_reminder_card.dart:8
- Reproduction & evidence: fixture auth_login.json: `"password_is_temporary": true`; app only shows `PasswordReminderCard` on home;
  no route forces `PUT /me/password`.
- Impact: the password that travelled over WhatsApp stays valid indefinitely (chat backups, shared phones) for health data.
- Proposed fix: product decision — force a change at first sign-in, or expire temporary passwords after N days. · Fix size: S

### MO-08 Arabic-keyboard digits are rejected or silently dropped in numeric fields
- Severity: P2 · Repo: Mobile-Flutter · Location: presentation/screens/new_measurement_screen.dart:138-143,
  onboarding_measurements_screen.dart:191-196, change_request_forms.dart:675,683, core/validation/validators.dart:66-67,
  daily_log_screen.dart:481,507
- Reproduction & evidence: test/audit/arabic_digits_input_test.dart (evidence arabic_digits_run.txt): `double.tryParse('٩٠٫٥')` → null
  ("invalid number" on waist/hip/thigh/arm and target weight); `Validators.numberInRange('٩٠')` → error; `int.tryParse('٣')` → null
  (training days); `FilteringTextInputFormatter.digitsOnly` turns typed `١٥٠` into `''` in the edit-grams dialog. The app already
  has `toLatinDigits` (core/utils/formatters.dart:17) but only for display. Weight itself is a slider (not affected).
- Impact: Gaza/Yemen patients on Arabic keyboards (Samsung/Gboard Arabic layout types ٠-٩ and ٫) cannot enter circumferences, a
  target weight or edit grams, with an "invalid number" message they cannot understand.
- Proposed fix: normalise with `toLatinDigits` and map `٫`/`،` to `.` before parsing; allow Arabic digits in the formatter. · Fix size: S

### MO-09 Measurement guide shows Arabic-only text in the English app
- Severity: P3 · Repo: Mobile-Flutter · Location: lib/data/static/measurement_guide_data.dart:11-20 (positionBadge, cornerHint, steps
  Arabic only), presentation/screens/measurement_guide_screen.dart:241,280
- Reproduction & evidence: by inspection — `Text(p.labelAr …)` and `part.positionBadge` rendered with no locale switch.
- Impact: English users see Arabic instructions. · Proposed fix: move to ARB. · Fix size: S

Top 3 friction points for "patient logs a first meal" (ranked):
1. Onboarding wizard before the first log: consent + mandatory weight + health form + notifications + 5 s wait (≈ 6 screens, ~8 taps
   after login). Smallest change: allow "later" on weight/health/notifications and land on home; ask the rest after the first log.
2. Credentials typed by hand from WhatsApp (`pt101.6t4t` + 10-char mixed-case password) on an Arabic keyboard (MO-08 family:
   digits in the username/password need a layout switch). Smallest change: a sign-in link/QR from the WhatsApp message, or
   username without mixed symbols.
3. Logging when the line drops then the session ends loses the meal silently (MO-02) — the patient's first logs are the most
   likely to be made offline right after a credential reset.

## Sweep (app)

- Hard-coded/sample data: grep of presentation/data for literal Arabic/English UI strings finds only language names, emoji,
  separators and the unlinked prototype `beetai_chat_screen.dart` (not reachable from any nav, its own header says so). Existing
  tests `test/presentation/no_fake_content_test.dart`, `data_from_fixtures_test.dart` pass in the baseline (1,052 tests).
  Empty-account rendering with real JSON: all repositories parse the empty account (contract test, empty variant); screens
  with the empty account NOT rendered separately.
- Dependencies: `flutter pub outdated`: firebase_core 3.15.2 (latest 5.2.0), firebase_messaging 15.2.10 (17.1.0), firebase_crashlytics
  4.3.10 (6.1.0), permission_handler 12 (13), device_preview 1.3.1 (3.0.0; only in debug per main.dart:58-70). `pub get` printed no
  advisories/retractions. No `dart pub audit` on this SDK; no CVE lookup possible offline.
- Notification/WhatsApp locale: push texts rendered in the patient's `locale` (PUT /me/notification-preferences sends it,
  notification_preferences_sync.dart:86); see feminine-form note above.

## Coverage
- I9 contract: VERIFIED — all reads + parsed write answers through the real Dio client, rich and empty account (24/25 pass;
  MO-01). Projection/insight with data and medical reports (off locally) NOT CHECKED.
- I2 app side: VERIFIED live — restart, lost answer, refresh+retry, reset/reuse data loss (MO-02), double tap; refresh within the
  30 s grace and server concurrency left to the cross-app agent.
- I5 app side: VERIFIED live for Gaza/Aden (MO-04, MO-05).
- Play gates: read-only (AAB NOT RUN — no SDK, dl.google.com blocked; merged manifest and APK strings NOT CHECKED).
- J2: by code + committed goldens; live BLoC-driven onboarding NOT RUN; regenerated goldens NOT RENDERED.
- Force update: inputs collected (MO-06). Sweep: done as listed; bidi of numbers+units in Arabic NOT CHECKED beyond goldens.

## Unverified
1. `OnboardingHealthScreen` blocks the wizard offline when the patient enters an allergy/medication (send failure → snack, no skip,
   onboarding_health_screen.dart:140-150) — read, not run.
2. Firebase AARs may add `com.google.android.gms.permission.AD_ID` to the merged manifest (would contradict data-safety.md).
3. Play target-API requirement value (needs the official page).
