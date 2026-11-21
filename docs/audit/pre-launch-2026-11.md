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
| Backend PHPUnit, MySQL 8, with F-DB-01's `->after()` removed locally (uncommitted) | MYSQL_PENDING | |
| `migrate:fresh --seed` on main, MySQL | **FAILS** at `2026_10_25_200300` (F-DB-01). With the local workaround: passes, seeds 7,851 foods, 12 s. | 12 s |
| Frontend `npm ci` / `eslint .` / `tsc --noEmit` / `next build` | pass / pass, 0 problems / pass / pass | 22 s / 20 s / 15 s / 41 s |
| Mobile `flutter pub get` / `gen-l10n` drift / `analyze --fatal-infos` | ok / none / **No issues found** | 45 s / – / 49 s |
| Mobile `flutter test` (goldens skipped, as CI) | **1,052 passed, 1 skipped, 0 failed** | 202 s |
| Mobile goldens (`--run-skipped --tags golden`, compare mode) | 33 passed, 17 failed. Expected: `dart_test.yaml` says the goldens render today's date and are regenerated on purpose. The diffs are 0.26–2.81% per screen. Not a finding. | 42 s |

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
