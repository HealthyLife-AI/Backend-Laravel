# Encrypted database backup

`php artisan db:backup` runs daily at 03:15 (Asia/Hebron). It dumps the whole database with a pure-PHP dumper
(`ifsnop/mysqldump-php`, no `mysqldump` binary needed), gzips it, encrypts it with AES-256-GCM and sends it to a
Telegram chat as `healthylife-YYYYMMDD-HHMM.sql.gz.enc`. The caption holds only the date, compressed size, table
count and the SHA-256 of the encrypted file. Nothing unencrypted leaves the server.

It needs the real cron entry (`php artisan schedule:run` every minute, already in `app.json`): unlike the other
jobs it is not self-triggered from API requests.

## Settings (Dokku config)

| Variable | Meaning |
|---|---|
| `BACKUP_PASSPHRASE` | At least 16 characters. **Not** `APP_KEY`. Keep a copy in a password manager away from the server; without it a backup cannot be opened. |
| `BACKUP_TELEGRAM_BOT_TOKEN` | Bot token from @BotFather. |
| `BACKUP_TELEGRAM_CHAT_ID` | Chat (or channel) the bot is a member of. |

If any is missing the command exits non-zero and dumps and sends nothing. The last outcome is visible under
`jobs.backup` in `GET /api/v1/system/scheduler-status`; failures are also reported to Sentry (stage only, no data).
Telegram refuses bot uploads above 50 MB: the command fails clearly before that (the database is about 7 MB today).

## Restore

1. Download the `.sql.gz.enc` file from the Telegram chat. Optionally check `sha256sum file.enc` against the caption.
2. Decrypt (any machine with this repo and PHP; the passphrase is prompted if `BACKUP_PASSPHRASE` is not set):

   ```
   php artisan db:backup-decrypt healthylife-20261029-0315.sql.gz.enc --out=backup.sql.gz
   ```

   A wrong passphrase or a damaged file fails with an error and writes nothing.
3. Unzip: `gunzip backup.sql.gz` (gives `backup.sql`).
4. Import into a **fresh** MySQL 8 database:

   ```
   mysql -u root -p -e "CREATE DATABASE healthylife_restore CHARACTER SET utf8mb4"
   mysql -u root -p --default-character-set=utf8mb4 healthylife_restore < backup.sql
   ```

5. Point a local copy of the app at it (`DB_DATABASE=healthylife_restore`) and run `php artisan migrate:status`:
   every migration should show `Ran`. Run `php artisan migrate` only if the backup predates a newer deploy.
6. Spot-check row counts (`users`, `subscribers`, `meal_plans`) against production before relying on it.

## File format (to decrypt without this app)

`"HLBK"` (4) | version `1` (1) | PBKDF2 iterations, big-endian uint32 (4) | salt (16) | IV (12) | GCM tag (16) | ciphertext.
Key: PBKDF2-HMAC-SHA256(passphrase, salt, iterations, 32 bytes). Cipher: AES-256-GCM; the first 37 bytes
(magic through IV) are the additional authenticated data. The plaintext is a gzip of the SQL dump.

## Tests

`tests/Feature/Backup/DatabaseBackupTest.php`. The dump-to-restore round trip needs a real MySQL/MariaDB and is
skipped unless `BACKUP_TEST_MYSQL=host:port:user:password` is set (the user must be able to create databases).
