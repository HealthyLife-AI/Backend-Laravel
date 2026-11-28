# HealthyLife AI: pre-launch audit (2026-11)

Status: **Phase 1 complete (2026-11-21). Waiting for the founder's reply before Phase 2.** The synthesis is at the end of this file. The per-area write-ups with full reproductions are in `docs/audit/findings/{backend,frontend,mobile,crossapp}.md`, and the evidence is in `docs/audit/evidence/`.
Branch: `audit/pre-launch-2026-11` (Backend-Laravel). Do not merge.

Audited commits (main at audit start):

| Repo | Commit |
|---|---|
| Backend-Laravel | `4f71f3f` |
| Frontend-Nextjs | `586d4fa` |
| Mobile-Flutter | `4679fa1` |

---

## Phase 0: environment and baseline

### Production block (hard rule 1)

Applied before any other command:

- `/etc/hosts`: `0.0.0.0` and `::` for `healthylife.apps.taqat.academy` and `frontend-nextjs-phi-ten.vercel.app`.
- Both hosts appended to `NO_PROXY` and `no_proxy` (`/etc/profile.d/zz-prod-block.sh`, sourced by every command that can reach a network).
- PHP runs in a Docker container (`hl-php84`). The wrapper adds `--add-host <host>:0.0.0.0` for both hosts and passes the same `NO_PROXY`, so the block also applies inside the container.

Proof:

| Client | Result |
|---|---|
| curl, through the proxy (env not yet applied) | `CONNECT tunnel failed, response 403`. The session's egress proxy also refuses both hosts, which is a second, independent layer. |
| curl, direct (NO_PROXY applied) | `curl: (7) Failed to connect to healthylife.apps.taqat.academy port 443 after 0 ms`, and the same for the Vercel host |
| PHP 8.4, Laravel `Http` facade (Guzzle/cURL), app booted | `gethostbyname` → `0.0.0.0`; `Illuminate\Http\Client\ConnectionException: cURL error 7: Failed to connect …` for both hosts |
| Dart 3.11.5 (`HttpClient` with `findProxyFromEnvironment`, as dio's default adapter) | `lookup → 0.0.0.0`; `SocketException: Connection failed (OS Error: Address family not supported by protocol, errno = 97)` for both hosts |

### Tooling deviations

- **PHP.** The host has PHP 8.3.6, but `composer.json` requires `^8.4`. php.new, the ondrej PPA and Debian apt mirrors are all refused by the session's egress proxy (403). PHP 8.4.28 runs from the official `php:8.4-cli` Docker image with only bundled extensions added: `pdo_mysql bcmath pcntl exif`. **`intl`, `gd` and `zip` are not available** (they need system libraries from apt). This matters: see F-OPS-01.
- **Composer** runs on the host (`--ignore-platform-req=php --no-scripts`), and the autoload and package discovery run under PHP 8.4.
- **Laravel Boost** v2.10.4 was installed and `boost:install` was run, per `CLAUDE.md`. Its changes to `composer.json`, `composer.lock` and `CLAUDE.md` stay local and are **not committed**: the generated guidelines say "do not change the application's dependencies without approval."
- **MySQL** 8.0.46 runs in Docker (`mysql:8.0`), with utf8mb4 / utf8mb4_unicode_ci.

- **Flutter** 3.41.8 (Dart 3.11.5), the version CI pins, from the official tarball. No Android SDK is installed yet (see the mobile section).

### Baseline numbers

| Suite | Result | Wall time |
|---|---|---|
| Backend PHPUnit, SQLite in-memory (as configured), run 1 | 1,507 tests · **4 failures** · 1 skipped · 2 risky | 136 s |
| Backend PHPUnit, SQLite, run 2 | identical | 139 s |
| Backend PHPUnit, **MySQL 8, unmodified main** | **every test errors at migration** (25 of 25 in `tests/Feature/Consent`; the full run was stopped). See F-DB-01. | n/a |
| Backend PHPUnit, MySQL 8, with F-DB-01's `->after()` removed locally (uncommitted) | 1,507 tests · **7 failures** · 1 skipped · 2 risky: the same 4 backup/intl failures plus **3 MySQL-only failures** in `tests/Feature/Phase4/ReportsTest.php`: `test_each_stream_writes_an_access_log_row_with_no_contents` ("The response is not a streamed response"), `test_file_streaming_is_rate_limited_per_user` (404 instead of 200) and `test_a_patient_delete_is_soft_and_the_file_is_purged_after_30_days`. Medical-report download behaves differently on production's engine; diagnosed under I1/I6. | 473 s |
| `migrate:fresh --seed` on main, MySQL | **FAILS** at `2026_10_25_200300` (F-DB-01). With the local workaround: passes, seeds 7,851 foods, 12 s. | 12 s |
| Frontend `npm ci` / `eslint .` / `tsc --noEmit` / `next build` | pass / pass, 0 problems / pass / pass | 22 s / 20 s / 15 s / 41 s |
| Mobile `flutter pub get` / `gen-l10n` drift / `analyze --fatal-infos` | ok / none / **No issues found** | 45 s / – / 49 s |
| Mobile `flutter test` (goldens skipped, as CI) | **1,052 passed, 1 skipped, 0 failed** | 202 s |
| Mobile goldens (`--run-skipped --tags golden`, compare mode) | 33 passed, 17 failed. Expected: `dart_test.yaml` says the goldens render today's date and are regenerated on purpose. The diffs are 0.26–2.81% per screen. Not a finding. | 42 s |

A second MySQL run was started by mistake while audit tests were being written into `tests/`. It counted 3,033 tests and is discarded.

**Session note, 17:29 UTC.** All subagents hit a usage limit, and the container worker restarted. **The restart wiped `/etc/hosts`, which removed the production block.** No network command ran between the restart and the moment I re-applied the block (verified again with curl: `Failed to connect … after 0 ms`). `/etc/profile.d/zz-prod-block.sh` now re-applies the hosts entries every time it is sourced, so a further restart can't silently lift the block.

The 4 SQLite failures are all in `tests/Feature/Backup/DatabaseBackupTest.php` (lines 271, 339, 375 and 384), and their cause is F-OPS-01.
The 2 risky tests: `PatientCredentialsTest::test_the_generated_password_is_never_logged` makes no assertions (`tests/Feature/Auth/PatientCredentialsTest.php:254`), so the "password never logged" guarantee isn't actually tested. `ReportsTest::test_a_tampered_file_does_not_decrypt` leaves an output buffer open.

---

## Findings (lead, Phase 0)

### F-DB-01 A fresh MySQL install from migrations fails on main
- Severity: **P1** · Repo: Backend-Laravel · Location: `database/migrations/2026_10_25_200300_add_patient_report_id_to_alerts_table.php:13`
- Reproduction: `php artisan migrate:fresh --seed --force` on MySQL 8.0.46 at `4f71f3f`:
  `2026_10_25_200300_add_patient_report_id_to_alerts_table ... FAIL — SQLSTATE[42S22]: Column not found: 1054 Unknown column 'checkin_id' in 'alerts' (SQL: alter table alerts add patient_report_id bigint unsigned null after checkin_id)`.
  `checkin_id` is added by `2026_10_27_100300_add_checkin_id_to_alerts_table.php`, which sorts later. Git history: the 10-27 file was committed on 2026-10-24 (`e4d9eb7`) and the 10-25 file on 2026-10-26 (`b97c247`). Production ran them in deploy order, so it works there. Every MySQL test errors as well (25 of 25 in `tests/Feature/Consent`). SQLite ignores `->after()`, which is why the suite is green.
- Impact: a rebuild of production from migrations (new host, disaster recovery without a usable dump, a staging copy) stops partway with a half-built schema. A MySQL CI can't go green. Live data is not affected today.
- Proposed fix: drop `->after('checkin_id')` from that migration. Column order has no effect and the migration has already run in production, so editing it is safe there. **Merge this before the backend CI PR.** · Fix size: S

### F-OPS-01 `db:backup` crashes after the dump without ext-intl, which is undeclared
- Severity: **P0 if production lacks intl** (no backups at all = data-loss exposure for live health data); otherwise P2 (undeclared dependency). · Repo: Backend-Laravel · Location: `app/Console/Commands/Backup/BackupDatabase.php:75` (and `:68`, `:81`)
- Reproduction: 4 failing `DatabaseBackupTest` cases under PHP 8.4 without intl. The command prints `Backup failed at unexpected: Unexpected failure (RuntimeException).` A temporary debug print showed the cause: `RuntimeException: The "intl" PHP extension is required to use the [format] method. @ vendor/laravel/framework/src/Illuminate/Support/Number.php:476`, raised by `Number::fileSize()` while the Telegram caption is built. This happens **after** the dump and encryption and **before** the upload, so nothing is sent.
- Context: `composer.json` doesn't require `ext-intl`. Production builds with Nixpacks (`nixpacks.toml`, provider `php`), which picks PHP extensions partly from composer's `ext-*` requirements, so intl being present in production is **not guaranteed**. Founder check: `dokku run <app> php -m | grep -i intl`, plus the last `backup` entry in the scheduler status. The command does record `ok: 0, failed_stage: unexpected` and reports to Sentry, so the failure is visible to anyone who looks.
- Proposed fix: replace `Number::fileSize()` with a 5-line local byte formatter, **or** add `"ext-intl": "*"` to `composer.json` (which makes Nixpacks install it, and fails `composer install` loudly where it's missing). Prefer the formatter, because it has no build impact. · Fix size: S

---

# Synthesis (end of Phase 1)

## 1. Launch verdict: **NO-GO** today; **GO WITH CONDITIONS** once batch 1 (below) is merged and the founder checks pass

- Allergen safety fails in three independent ways (A-01, A-02, A-03): a confirmed milk or peanut allergy does not stop milk or peanut foods reaching an **active** plan. This is already live for the pilot clinics.
- The patient app silently deletes meals logged offline whenever the session ends through a refresh failure (MO-02). The common trigger is the WhatsApp password-reset flow.
- Backups may not run at all (F-OPS-01, depends on production's PHP build). Public sign-up has no email verification and no global AI budget guard (BE-17, BE-16, X-07).

## 2. Phase 0 baseline

See "Baseline numbers" near the top. In short:
- **SQLite:** 1,507 tests, 4 failures (intl), 136 s.
- **MySQL 8:** fails at migration on unmodified main (F-DB-01). With a local one-line workaround: 1,507 tests, 7 failures, 473 s. 3 of those are a shared-fake-disk race in `ReportsTest` (BE agent), not a product bug.
- **Frontend:** lint, tsc and build all pass.
- **Mobile:** analyze is clean; 1,052 tests pass; the goldens drift only by date.
- **Release AAB:** NOT RUN locally (no Android SDK; dl.google.com is blocked). CI check in HealthyLife-AI/Mobile-Flutter#41.

The audit adds reproduction tests that **fail on purpose**:
- Backend: `tests/Feature/Audit/`, on this branch only.
- Mobile: `test/audit/`, on branch `claude/magical-pasteur-1uwou4`, no PR.

The I1 isolation matrix passes and should become permanent once moved out of the audit folder: 187 routes × 9 actors, 1,519 cases, on SQLite and MySQL.

## 3. P0 and P1 findings (merged across agents; source IDs in brackets)

| ID | Sev | Title | Location | Evidence (ran) | Fix |
|---|---|---|---|---|---|
| A-01 [BE-02, FE-12] | **P0** | 99.3% of foods (7,793 USDA) have no allergen tags, so the activation gate, the AI draft and the fallback draft are blind to allergy groups. | `database/seeders/UsdaFoodSeeder.php:58` (`Food::insert` skips `Food::booted` tagging); `2026_10_06_100000…:26` backfills only rows that exist at migrate time | `AllergenBypassTest` (untagged peanut food activates with no acknowledgement; the fallback draft for a peanut allergy contains peanut butter); Playwright `j3-*`: "حليب كامل الدسم" activated for a confirmed milk allergy, `allergen_conflicts: []`. **Production impact depends on founder check 14.** | Tag on seed, plus an idempotent `foods:tag-allergens` command (S/M) |
| A-02 [BE-03, FE-13] | **P0** | Editing an **active** plan publishes an allergen to the patient before any acknowledgement. | `app/Http/Controllers/Api/MealPlanController.php:98-121` | `AllergenBypassTest::test_editing_an_active_plan…`: PUT adding "Milk, whole" returns 200, the plan stays active, `allergen_ack_at` NULL, `plan_updated` push sent | 422 unless the optional `acknowledge_allergens` field is sent, plus a dashboard dialog (S + S) |
| A-03 [BE-04] | **P0** | The free-text allergy matcher misses the Arabic article and English plurals. | `AllergenConflictService::matchFood`; `AiDraftPlanService.php:840` | "فول سوداني" vs "زبدة الفول السوداني" → activates; "peanuts" vs "Peanut butter" → activates | One shared word-level matcher: strip ال, singularise, map to groups (M) |
| MO-02 | **P0** | Offline-queued meals and measurements are wiped when the session ends through a refresh 401 or `follow_up_ended`. | Mobile `lib/core/network/token_refresh_coordinator.dart:77-80` → `session_local_data_source.dart:84-90` → `app_dependencies.dart:171,385` | `test/audit/meal_log_queue_live_test.dart` against the local API (re-run by the lead): after a nutritionist password reset, 0 of 1 queued meals reach the server; after a lost refresh plus reopen after more than 30 s, both phones are logged out and 0 of 2 reach the server | Queue keyed by user id, kept across re-login, deleted only on an explicit logout with a warning (M) |
| F-OPS-01 [BE-05] | **P0 if prod lacks intl**, else P2 | `db:backup` dies after dump and encryption, before upload, when ext-intl is missing; intl is undeclared. | `app/Console/Commands/Backup/BackupDatabase.php:68,75,81` | 4 failing `DatabaseBackupTest` cases; root cause captured | Local byte formatter (S) |
| F-DB-01 | P1 | `migrate` on an empty MySQL database fails on main. | `2026_10_25_200300_add_patient_report_id_to_alerts_table.php:13` | `migrate:fresh` → `1054 Unknown column 'checkin_id'` | Drop `->after()` (S). Merge before CI PR HealthyLife-AI/Backend-Laravel#38 |
| BE-01 | P1 | A request-triggered scheduler run (`SCHEDULE_SELF_TRIGGER`) executes bulk jobs under the caller's `NutritionistScope`: alerts, reminders and summaries for **every other clinic are skipped** and the run is marked done. | `app/Http/Middleware/RunOverdueScheduledTasks.php:20`, `SelfScheduler.php:162`, `NutritionistScope.php:38` | `SelfSchedulerScopeTest`: "only saw 1 of 3 active patients"; the cron control passes | `Auth::forgetUser()` before `Artisan::call` (S) |
| X-07 | P1 | The Monday AI-summary batch can run twice in parallel (cron plus self-trigger), doubling Groq calls on the shared 8k TPM budget. | `bootstrap/app.php:48` (the only bulk job without `withoutOverlapping`); `GenerateWeeklySummaries.php:74` marks the run done only at the end | Mock Groq: 8 calls for 4 patients (`evidence/crossapp/j4-summaries-double-run.txt`) | `withoutOverlapping()` and mark the run done at start (S) |
| BE-16 [X-10] | P1 | No platform-wide token guard; AI timeouts leave no usage row; a 429 holds a web worker for the whole Retry-After (21 s seen); one user at 5 drafts/min ≈ 27k TPM against the shared 8k. | `OpenAiCompatibleClient.php:187-228`, `AiDraftPlanService.php:114`, `AppServiceProvider.php:133` | `AiUnderLoadTest`; mock Groq timing | Global token bucket, per-clinic quota, fall back immediately on 429, log failures (M) |
| BE-17 [FE-02] | P1 | No email verification and no bot friction on nutritionist sign-up. | `AuthController.php:49-60` | A mailinator sign-up added 7 patients and got an AI draft | Verify email before patients/AI, plus a captcha (M) |
| BE-01b [BE-10] | P1 | Credential reset, password change, logout and archive never clear the FCM token, so the old phone keeps receiving pushes. | `RefreshTokenService.php:210`, `PatientCredentialsService.php:52`, `AuthController.php:252` | `SessionRevocationTest` | Null `fcm_token` in `endAllSessions` (S) |
| BE-09 [X-06] | P1 | Concurrent refreshes with one token: more than 6 in parallel, or a stale pair used after the 30 s grace window, revoke every session of the user. | `RefreshTokenService.php:16,62-176` | MySQL, 8 workers: 6×200, 4×401, 0 active tokens | Serve the same child pair within the grace window (S) |
| BE-06 | P1 (P0 once reports are enabled) | Medical-report files are in no backup; `patient_reports.path` is encrypted with APP_KEY, so a restore without the same key orphans every file. | `config/filesystems.php:52`, `PatientReport.php:29` | By inspection | Off-site copy of the reports directory; APP_KEY and JWT_SECRET in the restore runbook (M) |
| X-11 | P1 | Reports over 2 MB fail although the app and policy promise 10 MB; over 8 MB the server returns 200 plus an HTML PHP warning. | `config/medical_reports.php:37`; no `upload_max_filesize`/`post_max_size` override | Live: 3 MB → 422; 9 MB → 200 + warning HTML | Ship PHP limits in `.php/` and check nginx's body limit (S) |
| X-01 [X-02, X-03, MO-05, FE-17] | P1 | One `SCHEDULE_TIMEZONE` decides "today" and push times for every patient: a Gaza 23:30 dinner is filed under the next day (Riyadh zone), and a Yemen 00:30 snack under the previous day (Gaza zone); pushes reach Gaza 1 h early in winter; appointments show ±1 h with no zone label. | `app/Support/ClinicDay.php:17-50`, `config/scheduling.php:21`, `AppointmentService.php:39-70` | `PatientDayBoundaryTest` (6 fail as intended, 3 controls pass); Playwright `j4-02/03` | Per-patient zone (spec 10b) (M) |
| MO-04 | P1 | Off-plan meals get the wrong meal type because the app sends `logged_at` in UTC. | Mobile `meal_log_repository_impl.dart:87,124` | `10:00Z` → breakfast vs the same instant as `13:00+03:00` → lunch | Send the local offset (S, mobile) or infer server-side with the patient's zone |
| MO-06 | P1 | No minimum-version / force-update mechanism and no version header, yet the first Play build stays on phones. | n/a | grep of both repos | Spec 10a. Must be in the first production Play build (M) |
| FE-16 | P1 | Normal dashboard navigation exceeds the 120/min API limit (patient page = 26 calls) and 429s render as empty states ("all on track"). | `AppServiceProvider.php:113-117` | Playwright on /en/alerts | Raise the per-user limit for authenticated nutritionists and render 429 as an error (S-M) |
| FE-20 | P1 | `next@16.3.4` has a critical advisory (npm audit: 1 critical, 2 high, 5 moderate). | `Frontend-Nextjs/package.json` | `npm audit --omit=dev` | Bump to ≥ 16.4.6 (S) |
| L-01 [X-14, BE-11, FE-01, FE-23, FE-24] | P1 (legal/store) | Public claims don't match the system: the policy omits Crashlytics (which Play's data-safety draft declares), dashboard Sentry, Google sign-in and the mail provider; it promises 30-day backup deletion and 12-month retention purges that no code performs; sign-up says "you agree to the terms" but no terms page exists; the landing page promises "every food is checked against allergies" (false, A-01); Sentry scrubbing doesn't remove Arabic text in breadcrumbs, contrary to the policy; the complaint contact is a personal Gmail. | `Frontend-Nextjs/content/legal/privacy.{en,ar}.md:78-118`, `RegisterForm.tsx:137`, `messages/en.json:734-737` | grep; code read | Policy text PR plus a terms page; implement or remove the retention promises (S-M) |
| FE-14 [BE-12] | P1 | A nutritionist cannot delete their account (no UI, no API) while the policy offers deletion. | `routes/api.php` | Route list; 403 | Decide the policy (what happens to patients), then add an endpoint (M) |
| BE-14 | P2, **P1 if `TRUSTED_PROXIES` is unset in prod** | All rate limits key on IP; behind an untrusted proxy every user shares one bucket. | `AppServiceProvider.php:157-174`, `config/trustedproxy.php:11` | 6 emails from one IP → 6th gets 429 | Founder check 15; key login on IP + email (S) |

P2/P3 findings are in the area files. The more important ones:
- an archived patient can't delete their account or download reports (X-13)
- the temporary WhatsApp password is never forced to change (MO-07, BE-13)
- a malformed token returns 500 and the app queue waits forever (MO-03)
- Arabic-Indic digits are rejected or silently dropped in the app, the dashboard and the API (MO-08, FE-05, BE-21)
- 11 composer advisories, including a reachable symfony ReDoS (BE-20)
- 77 alcoholic and about 454 pork foods are draftable (BE-22)
- three different kcal totals for the same logs (X-09)
- a deleted log comes back on replay (X-05)
- login CSRF on the BFF (FE-21)
- no CSP or frame-ancestors (FE-22)
- CORS answers `*` (P3, verified with a preflight)
- a new account is greeted "Welcome back, all patients on track" (FE-03)
- a new patient shows "stopped logging" (FE-04)
- the fallback draft is rice for breakfast and snacks (FE-07)

## 4. Founder must check in production

Replace `<app>` with the Dokku app name. **Run nothing that writes**, except where a check says "if YES, fix now".

| # | Check | Command | Expected |
|---|---|---|---|
| 1 | Old demo account with the seeded password `password` (DEPLOYMENT.md:287) | `dokku run <app> php artisan tinker --execute '$u=App\Models\User::where("email","nutritionist@example.com")->first(); echo $u ? "EXISTS id=".$u->id." old_pw=".(Illuminate\Support\Facades\Hash::check("password",$u->password)?"YES":"no") : "absent";'` | `absent` or `EXISTS … old_pw=no`. If `old_pw=YES`, follow DEPLOYMENT.md §"The demo account on Taqat" now. |
| 2 | Production timezone and self-trigger | `dokku config:get <app> SCHEDULE_TIMEZONE` · `dokku config:get <app> SCHEDULE_SELF_TRIGGER` | An explicit IANA zone (empty means the Asia/Riyadh default). Self-trigger: set it to `false` until BE-01 is fixed, **provided check 3 passes**. |
| 3 | dokku-cron runs the scheduler and the queue | `dokku cron:list <app>` · `dokku run <app> php artisan schedule:list` | One entry `* * * * * php artisan schedule:run`. Pushes depend on it: there is no worker process, the cron runs `queue:work --stop-when-empty`. |
| 4 | Persistent mount for reports | `dokku storage:report <app>` · `dokku config:get <app> MEDICAL_REPORTS_ENABLED` · `dokku config:get <app> REPORTS_ROOT` | A mount onto `/app/storage/app/private/reports` (or REPORTS_ROOT). Without one, every deploy deletes the reports. |
| 5 | Last backup and a real restore | `dokku run <app> php -r 'echo extension_loaded("intl")?"intl yes\n":"intl NO\n";'` · `dokku run <app> php artisan tinker --execute 'print_r(Cache::get("self-scheduler:last-run:backup:result"));'` | `intl yes`; `ok => 1` with `ran_at` in the last 24 h. Then restore the newest Telegram file **on your laptop**: `php artisan db:backup-decrypt <file>` → `gunzip` → import into an empty MySQL → `php artisan migrate:status` (all Ran) → log in. |
| 6 | Who can read the backup chat | Telegram: open the chat → Members / Administrators | Only you and the bot; the bot isn't in other groups. The passphrase is not stored in the same chat. |
| 7 | Debug off | `dokku config:get <app> APP_ENV` · `dokku config:get <app> APP_DEBUG` · `curl -s https://healthylife.apps.taqat.academy/api/v1/does-not-exist \| head -c 300` | `production` · `false` · JSON with a message and **no `trace`/`file` keys** |
| 8 | CORS | `curl -s -o /dev/null -D - -X OPTIONS https://healthylife.apps.taqat.academy/api/v1/auth/login -H 'Origin: https://evil.example' -H 'Access-Control-Request-Method: POST' \| grep -i allow-origin` | Today expected `*`: there's no `config/cors.php`, so the framework default applies (low risk with bearer tokens; P3). |
| 9 | Mail driver | `dokku config:get <app> MAIL_MAILER` · request a password reset for your own address | `smtp`/`ses`/`postmark`… (not `log`/`array`); the email arrives |
| 10 | Groq tier and retention | console.groq.com → Settings → Limits and Data controls · `dokku config:get <app> OPENAI_MODEL` | Limits for that model as assumed (≈ 8k TPM free); zero-data-retention enabled, because the policy claims it |
| 11 | Sentry DSNs set (don't print them) | `dokku config:get <app> SENTRY_LARAVEL_DSN \| wc -c` · `vercel env ls production \| grep -i sentry` | > 20 characters; `NEXT_PUBLIC_SENTRY_DSN` (or the configured name) present. The app uses Crashlytics only. |
| 12 | Queue health | `dokku run <app> php artisan tinker --execute 'echo DB::table("jobs")->count()." pending, oldest ".DB::table("jobs")->min("created_at").", failed ".DB::table("failed_jobs")->count();'` | Pending ≈ 0, oldest (unix time) less than 2 min ago, failed 0 |
| 13 | PHP upload limits | `dokku run <app> php -i \| grep -E 'upload_max_filesize\|post_max_size'` · `dokku nginx:report <app> \| grep -i body` | ≥ 12M each (likely 2M/8M today → X-11) |
| 14 | Untagged foods (decides A-01) | `dokku run <app> php artisan tinker --execute 'print_r(DB::table("foods")->selectRaw("source, sum(allergens is null) untagged, count(*) n")->groupBy("source")->get()->toArray());'` | `untagged` ≈ 0 for `usda`. If it equals `n`, every pilot plan was checked against nothing: review active plans of patients with allergies. |
| 15 | Trusted proxies | `dokku config:get <app> TRUSTED_PROXIES` | Taqat's proxy IP/CIDR (not empty, not `*` unless the app is reachable only through it) |
| 16 | Policy promise: old backups deleted after 30 days | Telegram chat history | Today nothing deletes them (BE-11) |

## 5. Data-flow table

Full table with V (verified) and I (by inspection) marks: `docs/audit/findings/crossapp.md` §7. Summary:

| Data | Destination | Disclosed in policy? | Play Data-safety |
|---|---|---|---|
| Draft input: age, sex, height, weight, activity, goal, conditions, lab notes, nutritionist notes, food list. No name, phone or allergies. **V** | Groq (US) | Yes (it over-discloses: it says allergies and medications are sent) | Health info collected; processor, so not "shared" |
| Weekly summary numbers **V** | Groq | Yes | same |
| FCM token, generic title and body, ids; no health data on the lock screen **V** | Google FCM | Yes | Device ID |
| Crash type, stack, device, installation id; message text redacted **I** | Crashlytics | **No** | Crash logs, Diagnostics |
| Scrubbed server exceptions **I** | Sentry (backend) | Yes | Diagnostics |
| Dashboard errors (Arabic text not scrubbed, FE-24) **I** | Sentry (web) | **No** | n/a (web) |
| Logs with SQL bindings and user ids **V** | Server disk | Not mentioned | n/a |
| Username, temporary password, app link **I** | WhatsApp (`wa.me`, also kept in browser history) | Not mentioned | Not app data |
| AES-256 DB dump **I** | Telegram | Yes | Not "shared" (encrypted) |
| Pages; refresh cookie via the BFF **I** | Vercel | Yes ("nothing stored" is inaccurate) | n/a |
| Nutritionist email and reset link **I** | Mail provider | **No** | n/a |
| Google OAuth token and identity **I** | Google sign-in | **No** | n/a (dashboard) |
| Medical report files **V** | Own disk (`reports`) | Yes | Health info, files |

## 6. Fix plan

**Batch 1** is 8 PRs, each at most ~200 changed lines excluding tests, one finding per PR. Merge in the order listed; the backend goes first.

| # | Repo | Branch | Finding | Change | Size |
|---|---|---|---|---|---|
| 1 | Backend | `fix/F-DB-01-migration-order` | F-DB-01 | Drop `->after('checkin_id')`. Then merge CI HealthyLife-AI/Backend-Laravel#38 | S |
| 2 | Backend | `fix/F-OPS-01-backup-without-intl` | F-OPS-01 | Local byte formatter instead of `Number::fileSize` | S |
| 3 | Backend | `fix/A-01-tag-seeded-foods` | A-01 | Seeder tags after insert; idempotent `foods:tag-allergens` command (run once in prod if check 14 shows untagged foods) | S/M |
| 4 | Backend | `fix/A-03-allergy-matcher` | A-03 (+ untagged half of A-01) | One word-level matcher (ال, plurals, group keywords); an untagged keyword match counts as a conflict that needs acknowledgement | M |
| 5 | Backend | `fix/A-02-active-plan-edit-gate` | A-02 | PUT on an active plan with new unacknowledged conflicts → 422 `allergen_ack_required` unless the optional `acknowledge_allergens: true` is sent. Rule 4: the app never calls it. | S |
| 6 | Frontend | `fix/A-02-dashboard-ack-on-edit` | A-02 | Handle that 422 with the existing acknowledgement dialog and resend | S |
| 7 | Mobile | `fix/MO-02-keep-offline-queue` | MO-02 | Per-user queue survives refresh 401 and `follow_up_ended`; flushed after the same user signs in again; explicit logout warns with the count | M |
| 8 | Backend | `fix/BE-01-self-scheduler-scope` | BE-01 | `Auth::forgetUser()` before the in-request `Artisan::call` | S |

**Batch 2** is a proposal, in order:
1. MO-06 force update: backend first, then mobile. **Before the first production Play build.**
2. X-07 summary overlap
3. BE-17 email verification
4. BE-16 AI budget guard
5. BE-10 FCM clearing
6. BE-09 refresh race
7. X-11 upload limits
8. FE-16 rate limits
9. FE-20 and BE-20 dependency bumps
10. L-01 policy and terms text
11. MO-04
12. BE-06 reports backup
13. Arabic digits (MO-08, FE-05, BE-21)
14. X-13
15. FE-14
16. X-01 per-patient zone (spec 10b)

## 7. Flow-improvement proposals (proposals only), ranked by activation impact

**Activation A: a nutritionist adds a first patient.** Today it takes 10 interactions from the landing page and 4 from the dashboard.

1. **First-run screen (J1).** Nine zero tiles, "Welcome back", "all patients on track" (`evidence/frontend/j1-03-first-run-ar.png`). Smallest change: a zero-patient card with a single "Add your first patient" CTA (FE-03). 0 extra steps, but no guidance at the most important moment.
2. **Competing CTA (J1).** "Complete your profile" (gender and WhatsApp) competes with "Add patient" on every page (j1-03). Show it only after the first patient exists.
3. **New patient labelled "stopped logging" (J1/J4, FE-04).** Show "Waiting for first login" until the first app sign-in.
4. **Fallback draft rebuild (J3).** 15–25 clicks to rebuild the rice-for-snacks fallback draft (j3-06). A slot-aware fallback (FE-07), and grams rounded to 5 g.
5. **Unlabelled appointment times (J4).** Times shown with no zone (FE-17). Show the zone label.
6. **Empty states on 429 (J4).** Empty states shown on 429 (FE-16): render "Too many requests, retrying…".

**Activation B: a patient logs a first meal.** Mobile goldens in `Mobile-Flutter/test/goldens/screens/`.

1. **Onboarding wizard.** About 6 screens and 8 taps (consent, mandatory weight, health form, notifications, a 5 s wait) before home. Allow "later" on weight, health and notifications.
2. **Typing credentials.** Credentials are typed by hand from WhatsApp: a mixed-case 10-character password on an Arabic keyboard. Add a sign-in link or QR code in the WhatsApp message, or use a digits-only username.
3. **First offline logs are lost.** The first logs are the most likely to be offline and right after a credential reset (MO-02, batch 1).

## 8. Coverage map

| Area | Verified (ran) | Read only | Not touched / why |
|---|---|---|---|
| I1 isolation | HTTP matrix 1,519 cases on SQLite and MySQL; report downloads; search; AI payloads (captured) | Push payload builders, other AI prompts | Exports (none exist beyond print views); queued jobs beyond BE-01 |
| I2 meal logs | Flutter queue live against the API; server concurrency (16 parallel replays × 6 rounds, unique index holds); refresh race | n/a | Two physical devices (simulated as two clients) |
| I3 allergens | Activation, edit, AI, fallback, Arabic and English | Templates and copies (code path shares the matcher) | Patient-logged off-plan alternatives vs allergies |
| I4 numbers | kcal, adherence, weight: dashboard vs app vs API | BMI, macros | Streaks (no streak values in demo data) |
| I5 today | Both zones, all bulk jobs, DST dates | n/a | Measurement reminders and check-ins under time travel |
| I6 recovery | Full backup → decrypt → restore → boot chain; size projection | Reports disk, release failure | Production mount (founder check 4) |
| I7 accounts | Refresh concurrency on MySQL; revocation; rate limits; patient deletion | Policy vs deletion | Nutritionist deletion (no endpoint exists) |
| I8 AI | 429, timeout, malformed and partial responses; prompt injection; captured payloads | Budget arithmetic | A real concurrent load run |
| I9 contract | 73 real fixtures through the real Dio client; 1 failure (MO-01, P3) | n/a | Projection, insight and report shapes |
| J1–J5 | Dashboard J1, J3, J4 (appointments), J5 (end follow-up, deletion page), AR and EN, Playwright; patient J2 data layer | Onboarding BLoC (code and goldens) | Alerts and quick-request actions (demo data produced 0 alerts); posts publishing; Google sign-in; admin pages |
| Play gates | targetSdk read (36); permissions vs data-safety; deletion route; demo:seed (no default password) | Health declaration inputs | **AAB build** (no SDK; CI PR); merged manifest (AD_ID?); APK strings |
| Supply chain | gitleaks over full history of all 3 repos (**no real secret**); npm audit; composer audit | pub advisories (none printed) | Firebase key restrictions in Google Cloud |
| Ops | Scheduler-status endpoint (pull-only, nobody alerted) | Sentry wiring | Production logs |

## 9. Unverified (max 10)

1. Production USDA foods are untagged → founder check 14 (decides whether A-01 is live).
2. Production lacks ext-intl → check 5 (decides F-OPS-01).
3. `TRUSTED_PROXIES` is unset in production → check 15.
4. The reports mount is missing → check 4.
5. Production PHP limits are the stock 2M/8M → check 13.
6. Two concurrent 06:00 alert runs create duplicate alerts (same pattern as X-07; not reproduced).
7. The Firebase libraries add `AD_ID` to the merged manifest (needs an Android SDK build).
8. The onboarding health step dead-ends offline when an allergy is entered (`onboarding_health_screen.dart:140-150`).
9. Unauthenticated BFF calls share one per-IP bucket (the Vercel egress IP) in production.
10. Play's current target-API requirement value: the code sets 36; confirm in Play Console.

## 10. Specs (no code)

### 10a. Force update (must ship in the first production Play build)

- **App → API.**
  - Every request carries `X-App-Version: <semver>` and `X-App-Platform: android|ios`.
  - Source: `package_info_plus`, already used in `app_version.dart`.
  - Add it in the Dio factory (`api_client_factory.dart:236`).
- **API.**
  - Config `app_versions.min_supported.{android,ios}` (env `MIN_APP_VERSION_ANDROID`, default `0.0.0`) and `app_versions.latest`.
  - A middleware on `me/*` and `auth/*` routes compares the header. If the version is below the minimum, it returns **426** `{ "code": "app_update_required", "message": "<localised>", "store_url": "…" }`.
  - A request **without** the header passes. That keeps current installed builds working (rule 4) until the minimum is raised deliberately.
  - `GET /api/v1/app-config` (public) returns `{min_supported, latest, store_url}` for a soft "update available" banner.
- **App.**
  - `AuthInterceptor` maps 426 to a session event `updateRequired`, handled like `follow_up_ended`.
  - `app_router.dart:30` redirects every route to a blocking `UpdateRequiredScreen` (Arabic and English) with an "Open Play Store" button.
  - The offline queue is **kept** (MO-02 principle) and flushes after the update.
- **Rollout.**
  - Ship the header and screen in build N with the minimum at `0.0.0`.
  - Raise the minimum only when an incompatible change is needed.
  - Builds older than N have no header, so they can never be forced. That is why it must be in the first build.
- **Tests.** Middleware unit test (below / equal / missing header); a Dart test where 426 leads to the blocking screen.
- **Founder decision.** The API host `healthylife.apps.taqat.academy` (the incubator's domain) is compiled into the app (`app_config.dart:15-17`). If that domain ever changes, installed builds break. Decide on an owned domain before the first production build.

### 10b. Per-user timezones

- **Data (additive).**
  - `users.timezone` (nullable IANA string) is the clinic zone, set in dashboard settings (default: the browser's zone).
  - Optional `subscribers.timezone` override, set by the nutritionist.
  - The patient's phone zone already reaches the server (`notification_preferences.timezone`, validated); today it is used only for quiet hours.
- **Resolver.** `ClinicDay::forPatient(Subscriber)`: override → phone zone → clinic zone → `SCHEDULE_TIMEZONE`.
  - Existing static calls remain the fallback.
  - Call sites that hold a patient pass it: `loggedBetween`, `AdherenceService` day keys, `TodaySummary`, reminders, morning push, measurements, weekly summary bounds, check-ins.
- **Jobs.** Bulk commands run every 15 min and pick patients whose **local** time just passed the target (06:00 / 07:30 / 09:00 / 20:00) and who have no row for that local date (reminders get a per-date `dedupe_key`). This also removes most of the self-trigger race (BE-01, X-07).
- **Appointments.** Availability is read in the clinic zone; responses keep the `timezone` field; the dashboard formats with that zone and shows a label; the app keeps device-local display (the correct instant).
- **Rule 4.** No field removed or retyped; `date` keys stay `Y-m-d`, computed in the patient's zone; old builds fall back to the clinic zone.
- **DST.** tzdata handles Asia/Gaza; no job falls in the switch hours (verified for 2026-10-24, 2027-03-27 and 2027-10-30).

---

# Phase 2, batch 1 (approved 2026-11-25)

| # | Finding | PR | Base | Notes |
|---|---|---|---|---|
| 1 | F-DB-01 | HealthyLife-AI/Backend-Laravel#39 | main | Merge first. Then the CI PR #38. |
| 2 | F-OPS-01 | HealthyLife-AI/Backend-Laravel#40 | main | |
| 3a | A-01 (matcher and keyword list) | HealthyLife-AI/Backend-Laravel#41 | main | |
| 3b | A-01 (tagging command, seeder, importer) | HealthyLife-AI/Backend-Laravel#42 | #41 | Stacked. |
| 4 | A-03 | HealthyLife-AI/Backend-Laravel#43 | #41 | Stacked. |
| 5 | A-02 (API) | HealthyLife-AI/Backend-Laravel#44 | main | |
| 6 | A-02 (dashboard) | HealthyLife-AI/Frontend-Nextjs#28 | main | After #44. |
| 7 | MO-02 | HealthyLife-AI/Mobile-Flutter#42 | main | Needs a new app build. |
| 8 | BE-01 | HealthyLife-AI/Backend-Laravel#45 | main | |

FE-20 (Next.js advisory) is not a patch-level bump: the critical advisories reach 16.4.0 and the 16.3 line ends at 16.3.8, so the fix needs 16.4.1 or later. It is first in batch 2.

Before/after evidence for A-02 (dashboard) and MO-02 is in `docs/audit/evidence/phase2/`.

---

# Phase 2, batch 2 (approved 2026-11-28)

All PRs are based on `main`. Where one needs another, the body says so.

| # | Finding | PR | Depends on |
|---|---|---|---|
| 1a | MO-06 force update, backend | HealthyLife-AI/Backend-Laravel#46 | none |
| 1b | MO-06 force update, app | HealthyLife-AI/Mobile-Flutter#43 | none to build; useful once #46 is deployed. **Must be in the first production Play build.** |
| 2 | FE-20 Next 16.4.6, `npm audit`: 0 vulnerabilities | HealthyLife-AI/Frontend-Nextjs#29 | none |
| 3a | X-07 summary batch overlap and double spend | HealthyLife-AI/Backend-Laravel#47 | none |
| 3b | BE-17 e-mail verification, backend (inert until enabled) | HealthyLife-AI/Backend-Laravel#48 | none |
| 3c | BE-17 e-mail verification, dashboard | HealthyLife-AI/Frontend-Nextjs#30 | needs #48 deployed first |
| 3d | BE-16 shared AI token budget | HealthyLife-AI/Backend-Laravel#49 | none (works best with #47) |

Verified on the **combined** tree (all batch 1 and batch 2 PRs merged in order onto `main`):
- backend: 1,594 tests, 0 failures on SQLite (149 s) **and on MySQL 8 (330 s)**, 2 risky and 1 skipped as at baseline;
- dashboard: lint, type-check and build pass on Next 16.4.6;
- app: `flutter analyze --fatal-infos` clean, 1,068 tests pass.

The combined run found one cross-PR conflict that git could not: a test in the A-03 PR assumed an edit to an active plan is saved and flagged, which the A-02 PR changes to "refused". The test was changed to hold in either merge order (HealthyLife-AI/Backend-Laravel#43).

Evidence: `docs/audit/evidence/phase2/` (BE-17 end-to-end screenshots and script, FE-20 smoke script and results).
