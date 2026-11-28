# Cross-app audit findings (agent `crossapp`, prefix X-)

Environment: own API on 127.0.0.1:8002 (container `hl-api-x`, DB `hl_audit_x`, MySQL 8.0.46, `SCHEDULE_SELF_TRIGGER=false`,
Groq pointed at a local mock on :8099). Backend tests in `tests/Feature/Audit/CrossApp/`, run on MySQL DB `hl_audit_x_test`:
`p84 vendor/bin/phpunit -c docs/audit/evidence/crossapp/phpunit.crossapp.xml tests/Feature/Audit/CrossApp/<File>.php`.
Evidence in `docs/audit/evidence/crossapp/`.

Test note: `firebase/php-jwt` checks `exp` against `time()`, not Carbon, so any test that fakes the clock must also set
`Firebase\JWT\JWT::$timestamp` (done in the CrossApp tests' `bearer()` helper).

---

## I5: "Today" is the user's own day

### X-01 One SCHEDULE_TIMEZONE cuts every patient's day: a Gaza or a Yemen patient's meal is filed on the wrong day whichever zone is chosen
- Severity: P1 · Repo: Backend-Laravel · Location: app/Support/ClinicDay.php:17-50 (all day cuts), app/Models/MealLog.php:100-104 (`loggedBetween`), app/Http/Controllers/Api/Logs/ClientMealLogController.php:39,58, app/Services/Adherence/AdherenceService.php:239,349
- Reproduction & evidence: `tests/Feature/Audit/CrossApp/PatientDayBoundaryTest.php` (MySQL). Same UTC instant 2026-11-20 21:30Z = 23:30 Fri in Gaza (UTC+2, winter) = 00:30 Sat in Yemen (UTC+3).
  ```
  Riyadh server / Gaza patient 23:30 Fri:  dashboard_day '2026-11-21' (expected '2026-11-20'); GET me/meal-logs?from=to=2026-11-20 → 0 logs
  Riyadh server / Yemen patient 00:30 Sat: passes
  Gaza server   / Gaza patient 23:30 Fri:  passes
  Gaza server   / Yemen patient 00:30 Sat: dashboard_day '2026-11-20' (expected '2026-11-21'); GET me/meal-logs?from=to=2026-11-21 → 0 logs
  ```
  The app groups by device-local day (Mobile-Flutter lib/presentation/screens/daily_log_screen.dart:283, bloc/meal_log/meal_log_cubit.dart:37 `isSameLocalDay`) and only survives because `_fetchAround` (data/repositories/meal_log_repository_impl.dart:247) fetches ±1 day. The dashboard (`meal-logs/daily`, the progress chart via `dailyCalories`, adherence `summary`) uses the server day. So for any patient whose zone differs from SCHEDULE_TIMEZONE, the late-evening (Gaza on a Riyadh server, Oct-Mar: 23:00-23:59) or just-after-midnight (Yemen on a Gaza server: 00:00-00:59 all year; Gaza summer = Yemen so only winter) meals move to the neighbouring day on the dashboard: Friday shows a missed dinner, Saturday shows dinner twice-planned-once-eaten. Pilot clinics are in BOTH zones, so no single value is right.
- Impact: nutritionist sees wrong per-day kcal/adherence for Gaza (or Yemen) patients; daily adherence status and the no-log alert work from the same shifted days; patient and dashboard disagree on "what I ate on Friday" (see I4).
- Proposed fix: per-patient day zone (see "Smallest correct model" below) · Fix size: M

### X-02 20:00 log reminder skipped for a Gaza patient who logged nothing on their own day (Riyadh server)
- Severity: P2 · Repo: Backend-Laravel · Location: app/Console/Commands/Notifications/SendLogReminders.php:46,57 (`last_logged_at < ClinicDay::today()`), same rule in app/Http/Controllers/Api/Clients/DashboardController.php:34,46 (`not_logged_today`)
- Reproduction & evidence: `PatientDayBoundaryTest::test_reminder_reaches_gaza_patient_who_logged_nothing_on_their_own_day_riyadh_server` FAILS ("Gaza patient got no 20:00 reminder for Sat although nothing is logged on their Saturday"); the control `test_control_reminder_reaches_gaza_patient_under_gaza_server` (same data, SCHEDULE_TIMEZONE=Asia/Gaza) PASSES.
- Impact: a Gaza patient who logs dinner at 23:00-23:59 (winter) is treated as "logged today" for the whole next day: no reminder, and the dashboard's "not logged today" tile undercounts. Mirror-image for Yemen under a Gaza server (00:xx logs).
- Proposed fix: compare against the patient's own day start (same model as X-01) · Fix size: S once X-01's helper exists

### X-03 Scheduled pushes land one hour early for Gaza in winter (Riyadh server); availability and slots are read in the server zone
- Severity: P2 · Repo: Backend-Laravel (+ dashboard, app display) · Location: bootstrap/app.php:34-68 (`->timezone(config('scheduling.timezone'))`), app/Services/Scheduling/SelfScheduler.php:117-133, app/Services/Appointments/AppointmentService.php:39-41,64-70
- Reproduction & evidence: `PatientDayBoundaryTest::test_scheduled_push_times_land_at_the_intended_local_hour_for_gaza_under_riyadh` FAILS: in Gaza local time alerts 05:00 (not 06:00), morning push 06:30 (not 07:30), log reminder 19:00 (not 20:00), measurement reminder 08:00 (not 09:00), Oct-Mar; correct Mar-Oct (Gaza summer = UTC+3). `test_slot_set_for_0900_shows_0900_to_a_gaza_patient_under_riyadh` FAILS: availability 09:00-10:00 yields a first slot the Gaza device shows as **08:00** (app parses with `toLocal()`, Mobile-Flutter lib/data/models/json_read.dart:49-50; book_appointment_screen.dart:330).
  Dashboard is internally inconsistent too: BookAppointmentDialog.tsx:103 / RescheduleDialog.tsx:46 format with `timeZone: slots.timezone` (server zone → "09:00") while PatientAppointmentCard.tsx:64 formats in the browser's zone (a Gaza browser → "08:00" for the same appointment). Instants are stored correctly in UTC (`appointments.starts_at`, `appointment_holds.slot_start`), so nobody misses a call by an hour as long as each side reads its own clock; the confusion is "the nutritionist set 09:00 and patients see 08:00".
- Impact: a Gaza nutritionist on a Riyadh server who means 09:00 Gaza publishes slots that start at 08:00 Gaza every winter; two screens of the same dashboard disagree by an hour. Evening reminder at 19:00 in winter instead of 20:00.
- Proposed fix: clinic (nutritionist) timezone for availability and for the dashboard labels; push jobs fan out per patient zone (model below) · Fix size: M

### DST (Asia/Gaza, tzdata 2026.6) — checked, no backend bug
Transitions computed from tzdata (`docs/audit/evidence/crossapp/dst-gaza-2027.txt`): 2026-03-28 00:00Z (02:00→03:00 Sat), 2026-10-23 23:00Z (02:00→01:00 Sat 24 Oct), **2027-03-27 00:00Z** (Sat, 02:00-02:59 local skipped), **2027-10-29 23:00Z** (Sat 30 Oct, 01:00-01:59 local repeated). No scheduled job sits in 01:00-02:59 local (03:15 backup, 03:30, 04:00, 06:00, 07:30, 09:00, 20:00), so under SCHEDULE_TIMEZONE=Asia/Gaza every job fires once at the right local time across both switches (06:00 local = 04:00Z before / 03:00Z after the spring change). Slots are generated per local day with `setTimeFromTimeString` and stored in UTC, so an appointment booked before a switch for a date after it keeps its local clock time. Residual risks (not backend): a phone with stale tzdata shows times ±1 h around a Palestinian rule change (Palestine has changed its rule dates often); the backup uses `Asia/Hebron` (bootstrap/app.php:57), a different but identical-rule zone — fine.
Under SCHEDULE_TIMEZONE=Asia/Riyadh the shift in X-03 starts/stops on these dates (e.g. reminder at 19:00 Gaza from 2027-10-30).

### Smallest correct model (design only)
Key fact found: the app ALREADY syncs the device's IANA zone to `notification_preferences.timezone` (Mobile-Flutter lib/data/services/notification_preferences_sync.dart:9-28; validated server side, `422 invalid_timezone`), and `NotificationPreference::effectiveTimezone()` (app/Models/NotificationPreference.php:36) already falls back to `scheduling.timezone`. It is used only for quiet hours.
1. **Additive migration**: `users.timezone` (nullable string, IANA) for nutritionists = clinic zone, set from the dashboard settings (browser `Intl.DateTimeFormat().resolvedOptions().timeZone` as default) and by admins; patients keep using `notification_preferences.timezone` (no new patient column, nothing for the installed app to send). Optional `subscribers.timezone` override editable by the nutritionist for a patient whose phone zone is wrong.
2. **One resolver**: `ClinicDay::for(Subscriber)` / `ClinicDay::forNutritionist(User)` returning a zone: patient override → patient device zone → nutritionist zone → `scheduling.timezone`. All existing static calls keep working (they become the fallback); every call site that has a patient in hand (`loggedBetween`, `AdherenceService` day keys, `TodaySummary`, `LoggingClock`, `SendLogReminders`, `SendMorningMessages`, `RemindMeasurements`, `WeeklySummaryService` week bounds, wellbeing, check-ins) passes the subscriber.
3. **Jobs**: run the bulk commands every 15-30 min instead of once a day and select patients whose LOCAL time has just passed 06:00/07:30/09:00/20:00 and who have no row for that local date (the commands already dedupe per day: morning `dedupe_key`, measurements `last_reminded_on`; reminders need a `dedupe_key` per local date). SelfScheduler slots become per-zone or are retired in favour of the cron.
4. **Availability/slots**: read availability in the nutritionist's zone; return `timezone` (already returned) = that zone; dashboard formats every appointment with that zone; app keeps device-local (correct instant).
5. **Rule-4 / installed app**: no response field removed or retyped; `date` keys in responses stay `Y-m-d` but are computed in the patient's zone; old app builds that never synced a zone fall back to the clinic zone (today's behaviour).

---

## I2: Meal-log integrity (server side, MySQL, live :8002)

Checked and holding: `meal_logs` has `unique(subscriber_id, idempotency_key)` (database/migrations/2026_09_13_090000_add_idempotency_key_to_meal_logs_table.php:36), so concurrency cannot double-count. Six rounds of 16 truly parallel POSTs with one key (`docs/audit/evidence/crossapp/i2-concurrent-idempotency.sh`, 9 php-server workers): every round exactly one 201; the other requests 200 or (2 of 6 rounds) one 500. `SELECT … GROUP BY idempotency_key HAVING count>1` → no rows. `lockForUpdate` is not used on this path and is not needed thanks to the index (SQLite would ignore it anyway; the index is enforced on both). Out-of-order sends: a log for 5 h ago sent after one for 1 h ago → both 201, `subscribers.last_logged_at` = max(logged_at) (recomputed, PatientEntryService.php:92-97). Access token expiry mid-replay: idempotency is independent of the token; the app halts on 401, refreshes, resumes (Mobile-Flutter lib/core/sync/sync_policy.dart:92). Replay after a lost response → 200 with the stored log (MealLogController.php:76-82). Two devices: keys are per device/entry, scoped per subscriber — no collision.

### X-04 Concurrent duplicate replay returns 500 (raw SQL error) instead of the stored log
- Severity: P3 · Repo: Backend-Laravel · Location: app/Http/Controllers/Api/Logs/MealLogController.php:76-96
- Reproduction & evidence: `i2-concurrent-idempotency.sh <token> 16`, 2 of 6 rounds: `14 200, 1 201, 1 500 "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1-…' for key 'meal_logs.meal_logs_su…"`. No duplicate row.
- Impact: the app treats 5xx as retryable (sync_policy.dart:81-90), so the meal is not lost; the cost is a Sentry error per race and a back-off delay. With APP_DEBUG on, the SQL text is returned.
- Proposed fix: catch `UniqueConstraintViolationException` around the insert and return the existing row with 200 · Fix size: S

### X-05 A replayed log comes back after the patient deleted it
- Severity: P3 · Repo: Backend-Laravel · Location: app/Http/Controllers/Api/Logs/MealLogController.php:76-82,135-148 (hard delete, so the key is forgotten)
- Reproduction & evidence: `docs/audit/evidence/crossapp/i2-delete-replay.out`: POST key K → id 320; DELETE → 204; POST same K again → **201**, new id 321.
- Impact: two devices, or a response lost before a delete: a late replay re-creates a meal the patient removed, so it counts again in kcal/adherence.
- Proposed fix: keep a tombstone (soft delete or a `deleted_idempotency_keys` row) and answer a replay of a deleted key with 410/200-deleted · Fix size: S

### X-06 Two concurrent refreshes both succeed; the device that keeps the older pair is logged out everywhere after 30 s
- Severity: P2 · Repo: Backend-Laravel + Frontend-Nextjs · Location: app/Services/Auth/RefreshTokenService.php:62-129,149-176; Frontend-Nextjs src/components/auth/AuthProvider.tsx:121-125 (known: "two browser tabs … would still race")
- Reproduction & evidence: `docs/audit/evidence/crossapp/i2-refresh-race.sh` → `i2-refresh-race.out`: two parallel `POST /auth/refresh` with one token → `200`, `200`, two different refresh tokens (chain R0→X→Y, the lock serialises correctly). 35 s later, using X → `401 "Refresh token was already used. All sessions have been revoked as a precaution."`, and Y is then dead too.
- Impact: dashboard with two tabs restored at once (shared HttpOnly cookie; whichever Set-Cookie lands last wins): ~50% chance the cookie holds X, and the next refresh (≥15 min later) logs the nutritionist out on every device. The Flutter app single-flights refresh (lib/core/network/token_refresh_coordinator.dart), so the patient side is only exposed if two app processes/isolates refresh at once (NOT CHECKED on device).
- Proposed fix: inside the grace window, a second presentation of the same parent returns the SAME child pair (store the child's encrypted plain token or re-issue the child id) instead of rotating the child; or a cross-tab lock in AuthProvider (BroadcastChannel / Web Locks) · Fix size: S-M

Not reproduced here (mobile agent's area, server side confirmed): a replay of an already-saved log whose food was since rejected or whose `logged_at` is now refused gets 422 from validation BEFORE the idempotency check (StoreMealLogRequest rules run first), so the app drops the entry as "rejected" although the server holds it — cosmetic, no loss.

---

## J4: Daily loop (schedulers, FCM faked)

Schedule (bootstrap/app.php:27-80): alerts 06:00, reminders 20:00, weekly summaries Mon 07:00, measurements 09:00, check-ins 09:00, ai-usage prune 04:00, db:backup 03:15 Asia/Hebron, reports purge 03:30, morning push 07:30, queue worker every minute. Six of them are also run by `SelfScheduler` from any web request (RunOverdueScheduledTasks, global middleware). Mock Groq: `docs/audit/evidence/crossapp/mock_groq.py` (modes success/429/timeout/malformed/partial/slow).

Verified OK (`tests/Feature/Audit/CrossApp/DailyLoopPushTest.php`, payloads captured in `docs/audit/evidence/crossapp/j4-push-payloads.json`): archived patients get no push (`Subscriber::active()` excludes `archived_at`); Arabic patient gets Arabic title/body, English patient English (`notification_preferences.locale`); FCM `data` carries only `notification_id`, `category`, `type`; lock-screen text is generic for every type (lang/{ar,en}/notifications.php — no food, weight, diagnosis or name); a second `morning:send` the same morning sends nothing (dedupe_key). Each push goes to the patient's own user row; no cross-clinic targeting found (commands iterate all clinics by design and notify `subscriber->user`).

### X-07 Monday AI-summary batch runs twice in parallel (cron + SelfScheduler), doubling Groq calls on the shared budget
- Severity: P1 · Repo: Backend-Laravel · Location: app/Console/Commands/AiSummaries/GenerateWeeklySummaries.php:74 (`markRan` only AFTER the whole batch), bootstrap/app.php:44 (no `withoutOverlapping`), app/Services/Scheduling/SelfScheduler.php:146-180 (its own lock, independent of the scheduler mutex). Same shape for alerts: app/Console/Commands/Alerts/EvaluateAlerts.php:79 (markRan at end; the cron's `withoutOverlapping` mutex does not cover SelfScheduler's `Artisan::call`).
- Reproduction & evidence: DB hl_audit_x, 4 active patients, mock Groq in `slow` mode. Started `php artisan ai-summaries:generate-weekly` (what cron runs at 07:00); 5 s later ran `SelfScheduler::runOverdue()` exactly as a web request's deferred callback does (`overdue=["summaries"] ran=["summaries"]`). Mock received **8** chat-completion requests for 4 patients, interleaved 17:32:34-17:33:09 (`docs/audit/evidence/crossapp/j4-summaries-double-run.txt`).
- Impact: with cron working (production), the first API request after Monday 07:00 starts a second full batch while the first is still pacing through patients (2 s gap + LLM time each). Every patient's summary costs two LLM calls on a platform-wide 8k tokens/min budget that AI drafts also need → more 429s, more templated fallbacks, nutritionists' drafts blocked longer on Monday morning. Alerts: two concurrent full evaluations (duplicate-alert risk relies on check-then-insert; not raced here).
- Proposed fix: call `markRan` (or take a dedicated cache lock shared with the cron, e.g. `Cache::lock('task:summaries')`) at the START of each bulk command, as SendLogReminders/morning:send already do; add `withoutOverlapping()` to the summaries schedule entry · Fix size: S

### X-08 The 20:00 log reminder has no per-day dedupe: any second run the same evening pushes again
- Severity: P3 · Repo: Backend-Laravel · Location: app/Console/Commands/Notifications/SendLogReminders.php:74 (`notify(...)` without a dedupe key; morning:send passes one)
- Reproduction & evidence: `DailyLoopPushTest` FAILS: running `notifications:send-log-reminders` twice at 20:00 Riyadh → Arabic patient receives `لا تنسَ تسجيل وجباتك اليوم` **twice** (morning push, run twice, is sent once).
- Impact: protection against a double send is only `markRan` up front + scheduler mutex; a manual run, a second app container/cron without `onOneServer`, or the ~1 s race between the cron starting and a request's SelfScheduler check doubles the nag for every patient.
- Proposed fix: dedupe key `log_reminder:{local Y-m-d}` like the morning message · Fix size: S

Nutritionist side: `alerts:evaluate` on the demo DB → 0 alerts (demo data has none due), so acting on alerts/quick requests/appointments through the API was only partially exercised — NOT CHECKED beyond `GET /alerts`. Backups (`db:backup`, Telegram) NOT CHECKED (needs real cron; Telegram faked only by inspection, see data-flow table).

---

## I4: One source for every number

| Number | Backend source | Dashboard | App | Same? |
|---|---|---|---|---|
| Day kcal logged | `AdherenceService::dailyCalories` (sum of float per-log kcal, `round(…,1)`), served by `clients/{id}/meal-logs/daily` and `clients/{id}/progress` | `Math.round(logged_calories)` (PlanVsActualChart.tsx:99,178) | sum of per-log `calories.round()` (daily_log_screen.dart:260, meal_log_repository_impl.dart:96,131; meal_log_cubit.dart:29) | **No** (X-09) |
| Day boundary for kcal | server day (SCHEDULE_TIMEZONE) | same | device-local day | **No** (X-01) |
| Macros per log | `MealPlanCalculatorService::macrosFor` in `MealLogResource` | API value | API value (`macros.calories`) or local `caloriesPer100g*g/100` before sync | Yes after sync |
| Adherence % | `AdherenceService::summary`; `GET clients/{id}/adherence` and `GET me/adherence` return identical JSON (verified: both 87.8, change_pp -8.4) | `87.8%` raw (AdherenceHeadline.tsx:51); `number(…,0)` = 88% in WeightInsightCard/GoalProjectionCard | `.round()` = 88% (progress_dto.dart:25) | Formula yes; display 87.8 vs 88 (P3) |
| Weight trend / change | `ProgressReportService`; `me/progress` = `clients/{id}/progress` (verified same `weight_trend`) | API | API | Yes |
| Streaks / achievements | none on server | none | computed on device from the local meal-log cache (achievements_screen.dart:42-58, device-local days) | app-only; resets with a reinstall/second phone (by inspection, P3) |
| "Not logged today" | `last_logged_at < ClinicDay::today()` (DashboardController.php:46, SendLogReminders.php:57) | API | device-local `isSameLocalDay` | **No** across zones (X-02) |

### X-09 The patient's day total and the nutritionist's day total differ for the same logs (rounding per log vs per day)
- Severity: P3 · Repo: Mobile-Flutter + Backend-Laravel · Location: Mobile-Flutter lib/presentation/screens/daily_log_screen.dart:260 + lib/data/repositories/meal_log_repository_impl.dart:131,96 (round each log, then sum); Backend app/Services/Adherence/AdherenceService.php:130 (sum, then round); Frontend src/components/progress/PlanVsActualChart.tsx:178
- Reproduction & evidence: live :8002, demo patient, three logs of Ful Medames 15 g (`docs/audit/evidence/crossapp/i4-kcal-compare.txt`): per-log 16.5 kcal → **app 51 kcal**, API `logged_calories` 49.5 → **dashboard 50 kcal**. Error grows by up to 0.5 kcal per log (a day of 15 logged foods can differ by ~7 kcal).
- Impact: patient and nutritionist quote different numbers for "what I ate today"; erodes trust in the counts during reviews. Combined with X-01, entire meals can differ for patients outside SCHEDULE_TIMEZONE.
- Proposed fix: the app sums the unrounded `macros.calories` and rounds once (display only), or the API adds a `day_total` the app shows · Fix size: S

---

## J3 (cross-app part): AI draft with mock Groq

`docs/audit/evidence/crossapp/j3-ai-draft.txt`, live :8002, `POST clients/2/meal-plans/ai-draft`: malformed JSON → 201 rule-based draft after 1 call (0 s); partial (`finish_reason=length`, empty meals) → 201 after 2 calls (1 s); timeout → 201 after the 12 s client timeout; **429 with `Retry-After: 20` → 201 after 21 s** (the request sleeps inside the PHP worker, then falls back). Every failure mode ends in an editable draft, none in a 5xx. Captured draft payload (`groq-draft-payload.txt`): `client_profile` = goal, target weight, sex, age, weight, height, activity, **health conditions, lab notes, the nutritionist's free-text notes** + calorie targets + food list; no name/phone/username; allergies are not sent (the allergen check runs server-side after). Weekly-summary payload (`groq-summary-payload.txt`): only adherence %, status, log count, alert counts, weight change — verified.

### X-10 A Groq 429 holds the request (and a php-server worker) for the whole Retry-After
- Severity: P2 · Repo: Backend-Laravel · Location: app/Services/Ai/OpenAiCompatibleClient.php (retry path, `sendRecorded(..., 2)`), bin/web.sh:79 (`artisan serve` with PHP_CLI_SERVER_WORKERS workers)
- Reproduction & evidence: mock mode `429` + `Retry-After: 20` → draft request took **21 s** (`j3-ai-draft.txt`).
- Impact: at launch the shared 8k TPM budget makes 429 the normal Monday/peak answer; each nutritionist pressing "AI draft" pins one of the few built-in-server workers for ~20 s, so a handful of concurrent drafts stalls every patient's API call (meal logs, refresh) behind them.
- Proposed fix: do not sleep on 429 inside the request — fall back immediately (or queue the draft and poll) and surface "AI busy, rule-based draft created" · Fix size: S
Not checked: the "same day" visibility of an activated plan in `GET me/meal-plan` across the timezone boundary (activation path covered by X-01's model: `TodaySummary::activePlan` compares `start_date` with the clinic day, so a Yemen patient at 00:30 on a Gaza server sees a plan starting "today" one hour late — by inspection, app/Services/Today/TodaySummary.php:82).

---

## J5 (cross-app part): reports, archive, deletion

### X-11 Medical reports above 2 MB fail with "The file failed to upload" although the app and the privacy policy promise 10 MB
- Severity: P1 · Repo: Backend-Laravel (deploy config) · Location: config/medical_reports.php:37 (`max_file_mb` 10); no `upload_max_filesize`/`post_max_size` override anywhere in the repo (.php/opcache.ini only sets OPcache; bin/web.sh runs `artisan serve` on stock nixpkgs PHP, defaults 2M/8M); privacy policy content/legal/privacy.en.md §5 "up to 10 MB each"
- Reproduction & evidence: `docs/audit/evidence/crossapp/j5-upload.txt` (php 8.4 defaults, same as stock nixpkgs: `upload_max_filesize=2M post_max_size=8M`): 3 MB PDF → `422 {"file":["The file failed to upload."]}`; 9 MB PDF → `HTTP 200` with body `<b>Warning</b>: PHP Request Startup: POST Content-Length of 9437611 bytes exceeds the limit of 8388608 bytes` (an HTML warning and a 200, not JSON). 600 KB at 20 KB/s → 201 in 30 s, download at 20 KB/s → 200 in 29.5 s, byte-identical. Production values are by inspection (no override found), not measured on production.
- Impact: a phone photo of a lab report (typically 2-5 MB) cannot be uploaded; the patient sees a generic failure. Over 8 MB the app gets a 200 with HTML, which it may treat as success or a parse error.
- Proposed fix: ship `upload_max_filesize=11M`, `post_max_size=12M` in `.php/` (same scan-dir mechanism as opcache.ini) and check the proxy body limit on Taqat; client-side downscale of photos · Fix size: S

### X-12 Report download has no Range support and upload is not resumable
- Severity: P3 · Location: app/Http/Controllers/Api/Reports/MyReportController.php (file stream)
- Reproduction & evidence: `curl -r 100000-100099 …/me/reports/1/file` → `HTTP200 614409B`, no `Accept-Ranges` (`j5-upload.txt`).
- Impact: on a 20 KB/s line a 10 MB report is ~8.5 min each way; any drop restarts from zero. Proxy/idle timeouts on the production edge NOT CHECKED.
- Proposed fix: `Accept-Ranges`/206 for downloads; chunked upload later · Fix size: M

### X-13 An archived patient cannot export or delete their own data in the app
- Severity: P2 · Repo: Backend-Laravel · Location: app/Http/Middleware/JwtAuthenticate.php:57-62 (follow-up gate on every authenticated route, including `DELETE me/account` and `GET me/reports/{id}/file`)
- Reproduction & evidence: `docs/audit/evidence/crossapp/j5-archive.txt`: after `POST clients/3/archive` the patient's still-valid access token gets `403 follow_up_ended` on me/today, me/meal-plan, me/meal-logs (GET and POST), me/progress, me/reports, auth/me; refresh → 401; fresh login → 403 follow_up_ended. Queued offline meals halt (`QueueHalt.followUpEnded`).
- Impact: correct for clinical access, but the patient loses the in-app "delete account" path and their own medical reports; only the public page's mailto remains (Frontend src/app/[locale]/account-deletion). Play accepts a web path, so not a blocker; data-subject access is weaker.
- Proposed fix: allow `DELETE me/account`, `GET me/reports*` and a data export for follow-up-ended patients · Fix size: S

Patient deletes account — verified clean (`j5-delete.txt`): `DELETE me/account` 204; old access token 401 at once (session_version); refresh 401; nutritionist `GET clients/2` 404 and `GET notices` shows `{patient_code: PT-102, deleted_at}` only; meal_logs, readings, reports, ai_summaries, notifications rows for the patient = 0. Nutritionist deletes own account: NOT CHECKED. Weekly summary content: covered under J4/X-07 (payload) only.

---

## 8. WhatsApp + notification locale, Arabic-Indic digits

- Push/in-app notification text follows `notification_preferences.locale` — verified (DailyLoopPushTest: ar → Arabic, en → English). `GET me/today` returns only `text_ar`/`motivation_ar` (app/Services/Today/TodaySummary.php:127-140): an English-UI patient gets Arabic reminders on the home screen (by inspection, P3).
- WhatsApp credentials message is built in the **dashboard's UI locale**, not the patient's (Frontend src/components/clients/CredentialsCard.tsx:35-41 uses the page's `t()`); an English-UI nutritionist sends English credentials to an Arabic patient (by inspection, P3). The password travels in the `wa.me` URL (CredentialsCard.tsx:69) — it ends up in WhatsApp chat history and the browser history of the nutritionist's device (by inspection).
- Arabic-Indic digits: the API refuses them (`docs/audit/evidence/crossapp/arabic-digits.txt`): `quantity_grams:"١٥٠"` → 422 "must be a number"; `weight_kg:"٧٢٫٥"` → 422. The app normalises in lib/core/utils/formatters.dart (by inspection); the dashboard normalises only credentials (src/lib/clients/credentials.ts). Any client that forgets gets an English error. P3: normalise digits server-side in a request `prepareForValidation`.

### X-14 Privacy policy omits Firebase Crashlytics, the dashboard's Sentry, Google sign-in and the e-mail provider
- Severity: P1 (store/legal) · Repo: Frontend-Nextjs · Location: content/legal/privacy.en.md:78-86 (provider table: Vercel, FCM, Groq, Sentry "server errors", Telegram) and privacy.ar.md
- Reproduction & evidence: `grep -ci crashlytics content/legal/privacy.en.md` → 0; the Play data-safety draft (Mobile-Flutter docs/store/data-safety.md:22,30) DOES declare "Crash logs and diagnostics … Google (Firebase Crashlytics)"; pubspec.yaml:43 `firebase_crashlytics`; Frontend `@sentry/nextjs` (src/instrumentation*.ts) is not covered by the "server errors" row; `POST auth/google` (GoogleAuthService) sends the nutritionist's Google token to Google's tokeninfo — not listed; password-reset mail goes through an unnamed mail provider.
- Impact: Play requires the policy to match the data-safety form; a reviewer or user finding crash diagnostics declared in the form but absent from the policy is a rejection/complaint risk.
- Proposed fix: add four rows (Crashlytics: crash type, stack, app version, device model, installation id; Sentry web: same scrubbing; Google sign-in: nutritionist e-mail/name from Google; mail provider: e-mail address + reset link) in both languages · Fix size: S

---

## 7. Data-flow table

V = verified (payload captured or request observed in this audit); I = by inspection of code/config.

| Data item | Destination | Purpose | Protection | In privacy policy? | Play Data-safety answer |
|---|---|---|---|---|---|
| Draft input: age, sex, height, weight, activity, goal, kcal targets, health conditions, lab notes, nutritionist free-text notes, food list (no name/phone/username; allergies NOT sent) | Groq (US) | AI meal-plan draft | HTTPS; Groq ZDR claimed by policy (account setting NOT CHECKED); no identifiers | Yes (§4) — but policy says allergies/medications/surgical history/likes are sent; the captured payload had none of those (policy over-discloses; harmless) | Health info "collected"; service provider → not "shared" — **V** (groq-draft-payload.txt) |
| Weekly summary input: adherence %, status, log count, alert counts, weight change | Groq | Monday summary | no identifiers | Yes (§4) | as above — **V** (groq-summary-payload.txt) |
| Weight-insight / plan-summary inputs | Groq | explanations | per policy table | Yes | **I** |
| FCM: device token, title, body (generic text, ar/en), data {notification_id, category, type} | Google FCM | push | lock-screen text has no health data; HTTPS | Yes (§6) | Device ID; not shared — **V** (j4-push-payloads.json, via fake FCM) |
| Crash type, stack, app version, device model, OS, installation id; message text redacted (RedactedError) | Firebase Crashlytics | crash reports | redaction in lib/core/logging/crash_reporter.dart; opt-out via setCrashlyticsCollectionEnabled | **No** (X-14) | "Crash logs, Diagnostics" collected, processor — **I** |
| Server exceptions: class, scrubbed message (SQL, digits≥7, Arabic text, query strings removed), stack, route | Sentry backend (EU) | error monitoring | `send_default_pii=false`, SentryScrubber drops body/auth/cookies/user; traces off by default | Yes (§6) | Diagnostics — **I** (scrubber read, no event captured) |
| Dashboard errors | Sentry web (`@sentry/nextjs`) | error monitoring | `sendDefaultPii:false`, `beforeSend: scrub` (src/lib/sentry.ts:12-14) | **No** (X-14) | n/a (web) — **I** |
| App errors | Sentry in app | — | no Sentry SDK in pubspec (Crashlytics only) | — | — **I** |
| Server log (`storage/logs`, LOG_LEVEL debug in .env.example): user_id on refresh-grace, AI fallback reasons, QueryException messages WITH SQL bindings (e.g. duplicate idempotency key; any failed insert carries its values) | host disk (Taqat) | ops | not scrubbed like Sentry; access = server admins | Not mentioned | — **V** (laravel.log lines seen for summaries fallback; SQL-in-message seen in the 500 body, X-04) |
| Patient username + temporary password + app link (+ greeting with name) | WhatsApp via `wa.me` link opened by the nutritionist | credential hand-off | E2E in WhatsApp; but in the URL → nutritionist's browser history; message in dashboard locale | **No** (WhatsApp not mentioned; the hand-off is the nutritionist's own WhatsApp) | not app data — **I** |
| Encrypted DB dump (AES-256) | Telegram | off-site backup | encrypted before upload (TelegramUploader, config/backup.php); key handling NOT CHECKED | Yes (§6, §7) | not "shared" (encrypted, provider) — **I** |
| Dashboard pages, access token in memory, refresh token in HttpOnly cookie via BFF | Vercel | hosting | HTTPS; BFF only for auth routes | Yes (§6) | n/a — **I** |
| Nutritionist e-mail + reset link | mail provider (MAIL_MAILER, prod value not in repo) | password reset | TLS depends on provider | **No** (X-14) | n/a — **I** |
| Google OAuth access token → tokeninfo; Google e-mail/name back | Google | nutritionist sign-in | server-side verification (GoogleAuthService) | **No** (X-14) | n/a (dashboard) — **I** |
| Medical report files | own server disk (`reports` disk) | nutritionist context | private disk, audit log on open, EXIF stripped per policy (NOT CHECKED: download was byte-identical for a PDF) | Yes (§5) | Health info, files — **V** (upload/download), protection **I** |

---

## Coverage

- Verified (ran): I5 day cuts on MySQL under Asia/Riyadh and Asia/Gaza (PatientDayBoundaryTest, 5 failing as intended + control passing); Gaza DST 2026/2027 transitions from tzdata 2026.6 vs every scheduled time; I2 concurrent duplicate keys (6×16 parallel), out-of-order, delete+replay, concurrent refresh + grace expiry; J4 morning/reminder targeting, locale, payload, double-run of reminders and of the summary batch (cron vs SelfScheduler); I4 kcal (app vs dashboard), adherence and weight endpoints for one patient; J3 draft under malformed/429/partial/timeout; J5 throttled upload/download, PHP limits, archive, patient account deletion; Arabic-Indic digits.
- Read-only: data-flow destinations (Sentry scrubbers, Crashlytics reporter, Telegram uploader, WhatsApp link, Google auth), privacy policy and data-safety draft, dashboard appointment formatting, app day grouping and sync policy.
- NOT CHECKED: alerts at 06:00 with real due data and nutritionist actions on alerts/quick requests/appointments (demo data produced 0 alerts); measurement reminders and check-ins under time travel; `db:backup` run; nutritionist deleting own account; duplicate-alert race under two concurrent `alerts:evaluate`; production PHP ini / proxy body and idle limits; Groq ZDR setting; EXIF stripping of photos; app-side display (mobile agent).

## Unverified
1. Production PHP upload limits are stock 2M/8M (no override in repo; Taqat may inject its own ini) — X-11.
2. Two concurrent `alerts:evaluate` (cron + SelfScheduler) create duplicate alerts (same markRan-at-end shape as X-07; not raced).
3. Older Android tzdata on patients' phones shows appointment times ±1 h after a Palestinian rule change.
4. Under `artisan serve` with few workers, concurrent AI drafts on 429 starve patient requests (X-10 shows 21 s per request; worker exhaustion not load-tested).
5. The 9 MB upload's 200-with-HTML response is parsed by the app as success (app side not run).
