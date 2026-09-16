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
