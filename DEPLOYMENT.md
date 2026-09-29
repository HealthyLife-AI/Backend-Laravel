# Deployment (S6-04)

Current target: Taqat (`https://healthylife.apps.taqat.academy`), a
Dokku-based PaaS. This documents what launch actually requires
operationally — it does not change how the app deploys today (see
"Migrations" below for why).

## Required environment variables

Full reference: `.env.example` (every var below is documented there with
its own reasoning — this is just the checklist).

| Group | Vars | Notes |
|---|---|---|
| App | `APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` | `APP_DEBUG=false` in production — leaving it `true` leaks stack traces to API clients. |
| Database | `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | |
| Auth | `JWT_SECRET`, `JWT_TTL`, `JWT_REFRESH_TTL` | `JWT_SECRET` must be distinct from `APP_KEY` and unique per environment — generate with `php artisan jwt:secret`, never copy the local one. |
| AI draft (S3-06, optional) | `OPENAI_BASE_URL`, `OPENAI_API_KEY`, `OPENAI_MODEL`, `OPENAI_TIMEOUT` | Blank = feature stays rule-based, fails closed, nothing breaks. |
| AI weekly summary (S5-03, optional) | `OPENAI_SUMMARY_*` | Falls back to the `OPENAI_*` vars above when blank — set only if you want the two features on separate quotas. |
| Admin account (optional) | `ADMIN_EMAIL`, `ADMIN_PASSWORD`, `ADMIN_NAME` | `db:seed` creates the admin panel login only when both email and password are set. No default is shipped. See "Seeding" below. |
| Demo account (leave unset in production) | `DEMO_NUTRITIONIST_PASSWORD` | `db:seed` creates `nutritionist@example.com` only when this is set, or when `APP_ENV=local`. Leave it unset on Taqat. |
| Scheduler | `SCHEDULE_TIMEZONE`, `SCHEDULE_SELF_TRIGGER` | See "Scheduler" below. |
| Patient consent (BR-17) | `CONSENT_VERSION`, `CONSENT_POLICY_URL` | **`CONSENT_VERSION` is required in production** (e.g. `2026-10-01`): it is the privacy-policy version patients must accept before the app's data endpoints open. Change it when the policy text changes and every patient is asked again. **Blank = the patient app is locked out** (503 `consent_not_configured`, fail closed) and the admin overview shows a warning. `CONSENT_POLICY_URL` is the dashboard's public `/privacy` page, e.g. `https://<dashboard-domain>/privacy`. |
| Patient log limits (optional) | `LOG_EDIT_WINDOW_HOURS` (48), `LOG_BACKDATE_LIMIT_DAYS` (7) | How long a patient may edit/delete their own entry, and how far back a new entry may be dated. |
| Push notifications (S5-06, optional) | `FIREBASE_CREDENTIALS_JSON_BASE64` | ⚠️ **Use this form, not `FIREBASE_CREDENTIALS_JSON`.** The raw-JSON form broke login/register outright on this project's own Taqat deployment — its unescaped `"` characters corrupted Taqat's env-var storage before the app could even log an exception. Base64's alphabet (`[A-Za-z0-9+/=]`) is immune to that class of failure. Verify with `GET /api/v1/system/ai-status` and `/system/fcm-status` after any redeploy — see below. |

After any deploy that touches these, confirm the running container
actually received them — setting a var in a dashboard does not prove the
process picked it up:

```
GET /api/v1/system/ai-status         # draft + summary LLM config, never the key
GET /api/v1/system/fcm-status        # which Firebase credential source is active
GET /api/v1/system/scheduler-status  # when the scheduled jobs last ran
```

**These three are admin-only** (a nutritionist or patient token gets `403`).
Get an admin access token by logging in with the `ADMIN_EMAIL` / `ADMIN_PASSWORD`
you set for the seed (see "Seeding" below), then pass it as a bearer token:

```
TOKEN=$(curl -s -X POST https://healthylife.apps.taqat.academy/api/v1/auth/login \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"email":"<ADMIN_EMAIL>","password":"<ADMIN_PASSWORD>"}' | jq -r .access_token)

curl -s -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  https://healthylife.apps.taqat.academy/api/v1/system/ai-status
```

The token lasts `JWT_TTL` (minutes); log in again when it expires. Don't paste a
real password into a shell that keeps history, or use `read -s` to prompt for it.

## Performance

Not yet confirmed either way whether Taqat's build runs these — same
honesty as the Migrations section below. Verified locally against this
exact codebase before recommending, not assumed safe:

```
composer install --no-dev  # optimize-autoloader:true in composer.json already applies here
php artisan config:cache
php artisan route:cache
```

Both cache commands were tested against this repo (231/231 tests still
pass under a cleared cache, and a live `php artisan serve` boot, login,
and `/system/ai-status` call all behaved identically under a cached one)
before writing this. Two things make them safe here specifically —
check both again if either changes before adding this to a deploy step:

- No `env()` call anywhere outside `config/*.php` (`config:cache` freezes
  `env()` to null everywhere else, which silently breaks any code that
  reads it directly — this codebase doesn't).
- Every route in `routes/api.php` is a controller class + method, never a
  closure handler (`route:cache` can't serialize a closure route).

**Always run `config:clear` and `route:clear` after testing these
locally** — a leftover `bootstrap/cache/config.php` gets loaded by every
subsequent `artisan` command, including `php artisan test`, ahead of
`phpunit.xml`'s own environment overrides. (Already gitignored —
`bootstrap/cache/.gitignore` — so this can't reach the repo by accident,
but it can still corrupt your own local dev/test session until cleared.)

OPcache is a PHP-level setting, not something this repo controls — worth
confirming it's enabled on whatever container Taqat builds, since it's a
larger win for a PHP API's per-request cost than either command above.

## Scheduler (alerts, log reminders, weekly AI summaries)

**No cron needed anymore (2026-09-27).** The three jobs now also run with
no cron at all: every request that reaches the app checks (at most once a
minute) whether a job's slot has passed without a run, and if so runs it
*after* the response is sent — `RunOverdueScheduledTasks` middleware →
`App\Services\Scheduling\SelfScheduler`. Commands record their own runs,
so cron and this path never repeat each other. Catch-up rules: alerts and
weekly summaries run late rather than never (both idempotent); reminders
only within 3 hours of 20:00, otherwise that evening is skipped. Saving a
meal log, body-composition reading or health profile also re-evaluates
that client's alerts immediately. Times are read in `SCHEDULE_TIMEZONE`
(default `Asia/Riyadh`; the app itself stays UTC). Disable with
`SCHEDULE_SELF_TRIGGER=false`.

Limit: jobs only catch up when *something* hits the app. With zero
traffic for days, nothing runs until the next request — the cron below is
still the way to get exact, traffic-independent timing.


**Real production gap, closed by this doc + `app.json`, not previously
wired anywhere**: `bootstrap/app.php`'s `withSchedule()` registers three
jobs (`alerts:evaluate` 06:00 daily — S5-01/FR-20, `notifications:send-log-reminders`
20:00 daily — S5-06/FR-22, `ai-summaries:generate-weekly` Mondays 07:00 —
S5-04/FR-21), but Laravel's scheduler does nothing on its own — something
outside the app must call `php artisan schedule:run` every minute. Nothing
in this repo did that before this doc: no `Procfile`, no `app.json`, no
CI/CD step. S6-11 ("End-to-end core loop verification") requires a real
alert to fire in production before pilot onboarding (S6-12) begins, which
is not verifiable without this wired up.

**Fix, root-caused at the platform level (Taqat is Dokku-based)**:
`app.json` at the repo root now declares a `cron` entry:

```json
{
  "cron": [
    { "command": "php artisan schedule:run", "schedule": "* * * * *" }
  ]
}
```

This is read by the [`dokku-cron`](https://github.com/dokku/dokku-cron)
plugin and registered automatically on every deploy — no code change,
no manual cron editing, survives redeploys. **Requires confirming the
`dokku-cron` plugin is actually installed on Taqat's Dokku host** —
ask Taqat, or check after the next deploy with the verification steps
below.

**Fallback, if `dokku-cron` is not available on Taqat**: a host-level
crontab entry that shells into the running container (needs SSH access
to the Dokku host itself, not the app container):

```
* * * * * dokku run <app-name> php artisan schedule:run >> /dev/null 2>&1
```

**Verification after any deploy that touches this**:

1. Confirm the 3 jobs are registered app-side: `php artisan schedule:list`
   — should print all three with correct next-due times.
2. Confirm the platform actually fires it: `dokku cron:list <app-name>`
   (if using the plugin) or `crontab -l` on the Dokku host (fallback path)
   — should show the `schedule:run` entry.
3. Confirm the 06:00 job actually ran (it recomputes every active
   client's adherence status, then evaluates the alerts). Any morning
   after 06:00 Riyadh (03:00 UTC), use either check:
   - **Admin overview**: log in as the admin. Under the page title,
     "آخر فحص يومي" shows today's date and time, how many patients were
     checked and how many statuses changed. A warning appears instead if
     the last run is older than a day or never happened.
   - **API**: `GET /api/v1/system/scheduler-status` with an **admin**
     token (log in with `ADMIN_EMAIL`, see "Required environment variables"
     above; any other role gets `403`). `jobs.alerts.last_ran_at` should be today at about
     03:00 UTC, `overdue` should be `false`, and `last_result` shows the
     counts (`patients`, `status_changes`, `stable`, `declining`,
     `stopped_logging`, `failures`).

   ```
   curl -s -H "Authorization: Bearer <admin_access_token>" -H 'Accept: application/json' \
     https://healthylife.apps.taqat.academy/api/v1/system/scheduler-status
   ```

   Telling cron apart from the self-trigger: if `last_ran_at` is close to
   03:00:00 UTC, cron fired it. If it is later (for example the first
   request of the morning, at 07:40), cron is not running and the
   self-trigger caught it up; check `dokku cron:list <app>` (step 2).
   `failures` above 0 means one or more patients failed; the errors are
   reported through Laravel's exception handler with the subscriber id.

These markers live in the cache store (`CACHE_STORE=database` on Taqat),
which every container shares. The command also writes one `Log::info` line
per run ("Daily check: …"), but with the default `LOG_STACK=single` that
line goes to a file inside the one-off cron container and is lost with it.
Set `LOG_STACK=stderr` if you want it in `dokku logs <app>`.

## Seeding (food catalog, admin account)

**Nothing in this repo runs `db:seed` on deploy.** Checked on 2026-09-28:
there is no `Procfile`, no release step and no buildpack/nixpacks config.
`app.json` only declares the scheduler cron. The `setup` script in
`composer.json` runs `migrate --force` only, and it is a local
convenience, not a deploy hook. `README.md` tells a developer to run
`php artisan migrate --seed` by hand. Earlier seeder comments claiming
"Taqat runs `db:seed --force` on every deploy" were wrong and have been
corrected.

Git history does show that `db:seed --force` has run on Taqat before.
Commit 73f154a says the seeder crashed "on the Taqat deploy", and commit
ec15206 says production ended up with two copies of every admin dish.
Whether Taqat's platform or a person ran it is not recorded, so treat
seeding as a **manual step** until a Taqat build log shows otherwise. No
`Procfile` or release step is added for it without a separate decision.

### What `db:seed` does

`DatabaseSeeder` runs, in order:

1. `RolesAndPermissionsSeeder`: roles and permissions (idempotent).
2. `ArabicFoodSeeder`: 58 curated Arabic dishes, `source = admin`,
   matched on a stable `seed_key`. It is insert-only: it adds a dish only
   when no row has its key, so admin edits and renames survive. A dish
   that the admin deletes is kept as a hidden `status = rejected` row, so
   it does not come back.
3. `UsdaFoodSeeder`: the full USDA SR Legacy catalog from the committed
   `database/data/usda_sr_legacy_foods.csv` (7,793 foods, ~690 KB), plus
   Arabic names for 144 of them from `database/data/usda_arabic_names.csv`.
   It inserts in batches of 500. It only inserts `usda_fdc_id`s that have
   no row yet, and only fills an Arabic name that is still empty, so
   admin edits survive. A USDA food that the admin deletes is kept as a
   hidden `status = rejected` row, so the seeder skips it and it does
   not come back. The raw 36 MB USDA download is not needed on the server.
4. Admin account: created only when `ADMIN_EMAIL` and `ADMIN_PASSWORD`
   are both set. It uses `firstOrCreate`, so an existing admin's password
   is never reset.
5. Demo nutritionist `nutritionist@example.com`: created only when
   `DEMO_NUTRITIONIST_PASSWORD` is set or `APP_ENV=local`. A local run
   without the variable gets a random password, printed once. It is
   never created on Taqat unless that variable is set there.

Measured on local MySQL, the first run on an empty database takes about
8.5 s in total (`UsdaFoodSeeder` ~7.0 s, `ArabicFoodSeeder` ~0.3 s). A
re-run takes about 3.7 s and adds nothing.

### Running it on Taqat

Set `ADMIN_EMAIL` and `ADMIN_PASSWORD` in the Taqat dashboard first (never
in a committed file), then:

```
dokku run <app> php artisan migrate --force
dokku run <app> php artisan db:seed --force
```

### Post-deploy check

1. Log in with `ADMIN_EMAIL`. You land on the admin overview, which proves
   the admin account exists.
2. The "Approved foods" tile shows **at least 7,851** (7,793 USDA + 58
   curated dishes). It is higher by the number of admin-added and
   approved nutritionist foods on that database. The 7,856 seen on the
   developer's local database is 7,851 + 5 local test rows.
3. The catalog breakdown shows 7,793 USDA foods.
4. As a nutritionist, search "موز". "موز طازج" (89 kcal) comes first.
5. `nutritionist@example.com` does not exist, unless you set
   `DEMO_NUTRITIONIST_PASSWORD` on purpose (see below).

### The demo account on Taqat

Before this change, `db:seed` created `nutritionist@example.com` with the
password `password` in every environment, and seeding has run on Taqat
(see above). Assume that account **exists on Taqat with that password**
until you check.

Changing a password does **not** end existing sessions by itself. Refresh
tokens are separate rows in `refresh_tokens`, and nothing revokes them on a
password change, so revoke them right after the change. An access token
that was already issued stays valid until it expires (`JWT_TTL`, 15
minutes by default). It cannot be revoked.

Run these in order:

```
# 1. Backup first (see "Backups" for the exact plugin command on Taqat)
dokku mysql:export <database-name> > backup-$(date +%F).sql

# 2. Open tinker in a one-off container
dokku run <app> php artisan tinker
```

Inside tinker:

```
// 3. Find the account and count the patients it owns
$u = App\Models\User::where('email', 'nutritionist@example.com')->first();
$u?->id;
DB::table('subscribers')->where('nutritionist_id', $u?->id)->count();

// 4a. Keep it: set a new password, then revoke every refresh token
$u->password = 'CHOOSE-A-LONG-RANDOM-PASSWORD';   // hashed by the model's cast
$u->save();
app(App\Services\Auth\RefreshTokenService::class)->revokeAllForUser($u);
DB::table('refresh_tokens')->where('user_id', $u->id)->whereNull('revoked_at')->count();   // must be 0

// 4b. OR remove it, only if the patient count in step 3 is 0
$u->delete();
```

If step 3 returns `null`, the account does not exist and there is nothing
to do. Deleting a user cascades to every patient it owns
(`subscribers.nutritionist_id`) and every plan it created
(`meal_plans.created_by`). If the patient count is above 0, use 4a.

## Migrations

**Open item, not resolved by this doc**: the exact mechanism that runs
`php artisan migrate` on a Taqat deploy is not confirmed. There is no
`Procfile` in this repo (`app.json` only declares the scheduler cron), and
migrations have apparently been
applying on `git push` to Taqat regardless — most likely Taqat's buildpack
auto-detects a Laravel app and runs migrations as part of its own
build/release step, but this has not been verified against an actual
Taqat build log.

Action for next deploy: check the Taqat build log after pushing a commit
that includes a new migration, and confirm the migration actually ran
(e.g. query `information_schema.migrations`-equivalent, or just hit an
endpoint that depends on the new column). If it turns out migrations are
NOT running automatically, the standard fix is a `Procfile` with a
`release: php artisan migrate --force` line — deliberately not added this
round, since the current process is apparently already working and
changing deploy behavior blind is a worse risk than the status quo.

## Backups

**Also unconfirmed**: whether Taqat's MySQL is a managed database with
its own snapshot/backup feature, or a plain container with nothing
backing it up. Check the Taqat dashboard's database panel for a
snapshots/backups tab before assuming there is nothing — do not run the
manual export below on the assumption it's the only copy if a managed one
already exists.

Safe manual runbook either way (Dokku's own MySQL plugin command — confirm
the exact plugin/command name against the Taqat dashboard, since this
project's provisioning is not confirmed):

```
dokku mysql:export <database-name> > backup-$(date +%F).sql
```

Recommended cadence: daily, kept for at least 2 weeks, before Sprint 6's
pilot onboarding (S6-12) puts real client data in this database for the
first time — up to that point every environment has held only test/demo
data, so backup pressure changes materially at that point.

## Rollback

`php artisan migrate:rollback` is **not** uniformly safe in this
migration history. Three are known NOT reversible without a data-loss
review first:

- `2026_09_14_090000_reclassify_adherence_status_by_direction.php` —
  its `down()` exists, but the old `on_track`/`needs_attention` split was
  never recorded before being collapsed to `NULL`; rolling back recovers
  the enum values, not the original data.
- `2026_09_10_100000_dedupe_admin_seeded_foods.php` — `down()` is
  deliberately empty; it deleted true duplicate rows, which cannot be
  un-deleted.
- `2026_09_06_092430_make_email_nullable_on_users_table.php` — its
  `down()` re-adds `NOT NULL`, which will fail outright once any client
  (created with no email, by design — see PRD F-1) exists in the table.

Every other migration in `database/migrations/` is a plain additive
`up()`/`down()` pair and rolls back safely. Take a backup (above) before
rolling back past any of the three named here regardless.
