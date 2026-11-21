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
