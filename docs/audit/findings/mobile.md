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

## I2 Meal-log integrity (app side) — by inspection so far, live tests in progress

Established by reading code (file:line), to be confirmed by Dart tests:
- Idempotency key is generated once per food log when the meal is logged (`meal_log_repository_impl.dart:88,125`) and
  stored inside the queued body, kept across retries (`pending_write.dart`), so a replay after a lost response returns
  the server's 200 + existing row (fixture `me_meal_logs_post_replay`: same id). Per meal, not per attempt — OK.
- Queue persisted in SharedPreferences key `pending_writes.v1` (`core/storage/storage_service.dart`), removed only after
  server answer (`offline_queue.dart:233-237`). Survives restart.
- RISK (to verify live): any refresh 401 (reuse detection after the 30 s grace, nutritionist credential reset via
  `POST /clients/{id}/reset-password` → `endAllSessions`, patient password change on another device, 30-day refresh
  expiry) → `TokenRefreshCoordinator` calls `store.clear()` (`token_refresh_coordinator.dart:77`) →
  `SessionLocalDataSource.clear` → `forgetPatientCaches`, whose FIRST entry is the offline queue
  (`app_dependencies.dart:171,385-386`) → unsent meal logs and measurements are deleted silently. Same on
  `403 follow_up_ended` (`auth_interceptor.dart:60-61`).

## Coverage
- I9: verified (all read endpoints + write answers parsed via real Dio client).
- I2, I5, Play gates, J2, force update, sweep: in progress / see below.

## Unverified
- (none yet)
