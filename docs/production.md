# Production runtime (Taqat: Dokku + Nixpacks)

How the API is served and kept fast on the shared host (8 cores, 32 GB), and how to check after every
deploy that it really runs that way. Nothing here needs a change on Taqat's side.

## The processes

| Process | Command | Notes |
|---|---|---|
| `web` | `sh bin/web.sh` | The only long-running process Dokku runs. |
| `release` | `php artisan migrate --force` | Runs in a **throwaway container** before the new `web` starts. Only migrations belong here: a file written there (a cache) never reaches the container that serves requests. |
| cron | `php artisan schedule:run` every minute (`app.json`) | Runs the scheduled jobs and, every minute, the queue worker (below). |

The `Procfile`:

```
web: sh bin/web.sh
release: php artisan migrate --force
```

`bin/web.sh` is what starts the server (it is a script only so the Procfile stays a plain command):

1. `php artisan optimize`: caches config, routes, events and views **in the web container**. If it fails, the app
   still starts, uncached, and logs a warning.
2. Adds `.php/` to `PHP_INI_SCAN_DIR` (the empty first entry keeps PHP's own scan directory, so no extension is
   lost). `.php/opcache.ini` switches **OPcache on for the CLI SAPI**: the web server is PHP's built-in server
   (`php artisan serve`), which runs under the CLI SAPI, where OPcache is off by default.
3. Logs whether OPcache is really on, and the worker count.
4. `exec php artisan serve --host=0.0.0.0 --port=$PORT --no-reload`. `--no-reload` is what makes `artisan serve`
   honour `PHP_CLI_SERVER_WORKERS`, and it hands the whole environment to the workers.

`opcache.validate_timestamps=0` means PHP never re-reads a source file while the container lives. That is right
for a container (a deploy builds a new one) and wrong for a machine where code is edited in place.

### Why `optimize` is not in the release phase

Dokku's `release` runs in its own container. `bootstrap/cache/config.php` written there is thrown away with it.
`php artisan optimize` therefore runs at web start. A consequence: with a cached config, `env()` returns `null`
outside `config/`. Nothing in `app/`, `routes/` or `bootstrap/` calls `env()` (a test guards this:
`test_nothing_outside_config_reads_env`). The database seeders do read `env()`; they are run by hand with
`dokku run`, where the variables are real environment variables, so they still work.

## Environment values to set

| Variable | Value | Why |
|---|---|---|
| `PHP_CLI_SERVER_WORKERS` | `8` | Number of concurrent requests the web process serves. Each worker is about 70 MB RSS, so 8 is about 560 MB; with 8 cores there is no gain past roughly 8, and a burst of slow requests (an AI draft holds a worker for up to 20 s) cannot take them all. Raise it only after the load test (`docs/load-test.md`) shows workers saturated while CPU is idle. |
| `QUEUE_CONNECTION` | `database` | Already set. The push fan-out is queued there. |
| `CACHE_STORE`, `SESSION_DRIVER` | `database` | Already set. |
| `LOAD_TEST_DEMO_API_RATE_PER_MINUTE` | unset | Set (for example `3000`) **only** for the night of a load test, then unset. Applies to demo accounts only. |

Nothing else changes. No new migration.

## The queue

The platform has no separate worker process, so the every-minute cron works the queue:
`queue:work database --stop-when-empty --max-time=50 --tries=3` (no overlap, in the background). It handles the
only queued job today, `SendPushNotifications`: the FCM sends of a post, the morning push and the other bulk
notifications, 100 per job. In-app notifications are written immediately; the phone push follows within about a
minute.

If something looks stuck: `GET /api/v1/system/scheduler-status` (admin) shows `queue.pending`,
`queue.oldest_pending_seconds` (more than about 120 means the cron is not running) and `queue.failed`.

## After every deploy: check it

From a shell in the web container (`dokku enter <app> web`, or `dokku run`):

```sh
# 1. The OPcache setting is read from the scan directory, and is on for the CLI.
PHP_INI_SCAN_DIR=":/app/.php" php -i | grep -E "opcache.enable_cli|opcache.validate_timestamps|Additional .ini"
# Expected: opcache.enable_cli => On => On, opcache.validate_timestamps => Off => Off, and /app/.php/opcache.ini parsed.

# 2. The opcache extension is installed at all.
php -m | grep -i "opcache"
# Expected: Zend OPcache. Nothing printed means the Nixpacks PHP has no opcache; see the note below.

# 3. The caches exist (written by bin/web.sh at start).
ls bootstrap/cache
# Expected: config.php  events.php  packages.php  routes-v7.php  services.php

# 4. What the migrations did.
php artisan migrate:status | tail -5
```

A shell in the container is another process than the one serving requests, so also ask the serving process
itself. This is the authoritative check:

```sh
curl -s -H "Authorization: Bearer <admin token>" https://<api>/api/v1/system/scheduler-status | python3 -m json.tool
```

Look at `runtime`:

```
"runtime": {
  "php": "8.4.x", "sapi": "cli-server", "server_workers": 8,
  "opcache": { "enabled": true, "cached_scripts": 600, "hit_rate_percent": 99.x, ... },
  "config_cached": true, "routes_cached": true, "events_cached": true
}
```

and the deploy log shows `OPcache is on for the web process.` (or a `WARNING` line if it is not).

If `opcache.enabled` is `false`: the Nixpacks PHP build either has no opcache extension or ignores
`PHP_INI_SCAN_DIR`. Nixpacks builds PHP with `withExtensions (pe: pe.enabled ++ [...])` plus every `ext-*` in
`composer.json`, and ignores `ext-zend-opcache` for PHP 8; `opcache` is normally in the default set. If it is
not, add `"ext-opcache": "*"` to `composer.json` `require` (this is the only change that would need a
re-deploy; it was not added blind because adding an extension the build already has can break it).

## Worst cases (what a burst can cost a worker)

| Work | Before | Now |
|---|---|---|
| One FCM push | up to 30 s (Laravel's default timeout), twice when the token had to be fetched | 3 s to connect, 5 s in total, per request |
| Publishing a post to N patients | N pushes sent after the response, one by one, in the same worker | N in-app rows written; the sends are queued, 100 per job, and sent by the cron's worker |
| 07:30 morning push, other bulk jobs | one by one inside one cron process | rows written, sends queued as above; each job also never runs twice at once |
| One AI draft (synchronous) | up to four provider requests of 12 s each (JSON mode refused then retried as plain text, and the whole thing tried a second time) = **48 s**; with a 10 s `Retry-After` wait in the middle, 12 + 10 + 24 = 46 s | **20 s** in total, retries and waits included, then the rule-based draft. Each request's timeout is cut to what is left of the 20 s, and a retry is only started if at least 6 s remain after any wait |

The other two interactive AI calls (plan summary, weight insight) still have the old worst case of two
12 s requests (24 s); they are rare and not part of a burst.
