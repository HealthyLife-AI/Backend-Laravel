# HealthyLife AI: pre-launch audit (2026-11)

Status: **IN PROGRESS.** This file is committed after every invariant and journey, so it survives if the session ends.
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
| Dart | see below (recorded once the Flutter SDK is installed) |

### Tooling deviations

- **PHP.** The host has PHP 8.3.6, but `composer.json` requires `^8.4`. php.new, the ondrej PPA and Debian apt mirrors are all refused by the session's egress proxy (403). PHP 8.4.28 runs from the official `php:8.4-cli` Docker image with only bundled extensions added: `pdo_mysql bcmath pcntl exif`. **`intl`, `gd` and `zip` are not available** (they need system libraries from apt). This matters: see F-OPS-01.
- **Composer** runs on the host (`--ignore-platform-req=php --no-scripts`), and the autoload and package discovery run under PHP 8.4.
- **Laravel Boost** v2.10.4 was installed and `boost:install` was run, per `CLAUDE.md`. Its changes to `composer.json`, `composer.lock` and `CLAUDE.md` stay local and are **not committed**: the generated guidelines say "do not change the application's dependencies without approval."
- **MySQL** 8.0.46 runs in Docker (`mysql:8.0`), with utf8mb4 / utf8mb4_unicode_ci.

(Baseline numbers follow below as they are measured.)
