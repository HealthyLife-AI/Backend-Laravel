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
| Push notifications (S5-06, optional) | `FIREBASE_CREDENTIALS_JSON_BASE64` | ⚠️ **Use this form, not `FIREBASE_CREDENTIALS_JSON`.** The raw-JSON form broke login/register outright on this project's own Taqat deployment — its unescaped `"` characters corrupted Taqat's env-var storage before the app could even log an exception. Base64's alphabet (`[A-Za-z0-9+/=]`) is immune to that class of failure. Verify with `GET /api/v1/system/ai-status` and `/system/fcm-status` after any redeploy — see below. |

After any deploy that touches these, confirm the running container
actually received them — setting a var in a dashboard does not prove the
process picked it up:

```
GET /api/v1/system/ai-status   # draft + summary LLM config, never the key
GET /api/v1/system/fcm-status  # which Firebase credential source is active
```

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
3. Confirm it actually ran: after the next 06:00, query the `alerts`
   table for rows with `created_at` around that time for any client with
   a stale `last_logged_at` — a genuine no_log alert firing is proof the
   whole chain (cron → schedule:run → alerts:evaluate → DB write) works,
   not just that it's registered.

No dedicated log output is configured for these jobs (no
`->appendOutputTo()` on any of the three) — a silent failure shows up as
"no new alerts/summaries ever appear," not as an error anywhere. Worth
adding output logging before the pilot (S6-12) if this needs to be
diagnosable without querying the database directly.

## Migrations

**Open item, not resolved by this doc**: the exact mechanism that runs
`php artisan migrate` on a Taqat deploy is not confirmed. There is no
`Procfile` or `app.json` in this repo, and migrations have apparently been
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
