<?php

namespace App\Console\Commands\Backup;

use App\Exceptions\Backup\BackupException;
use App\Services\Backup\BackupCrypto;
use App\Services\Backup\DatabaseDumper;
use App\Services\Backup\TelegramUploader;
use App\Services\Scheduling\SelfScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Number;
use Throwable;

/**
 * Daily encrypted off-site backup: dump -> gzip -> AES-256-GCM -> Telegram.
 *
 * Nothing unencrypted ever leaves the server, and nothing sensitive is printed: the
 * passphrase, the bot token and the chat id are read from the environment and never
 * written to the output, the logs, the caption or an error report.
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup';

    protected $description = 'Dump the database, encrypt it with BACKUP_PASSPHRASE and send it to a Telegram chat';

    public function handle(DatabaseDumper $dumper, BackupCrypto $crypto, TelegramUploader $uploader, SelfScheduler $scheduler): int
    {
        $passphrase = (string) config('backup.passphrase');
        $token = (string) config('backup.telegram.bot_token');
        $chatId = (string) config('backup.telegram.chat_id');

        $missing = array_keys(array_filter([
            'BACKUP_PASSPHRASE' => $passphrase === '',
            'BACKUP_TELEGRAM_BOT_TOKEN' => $token === '',
            'BACKUP_TELEGRAM_CHAT_ID' => $chatId === '',
        ]));

        if ($missing !== []) {
            $this->error('Backup not run: set '.implode(', ', $missing).'. Nothing was dumped or sent.');

            return self::FAILURE;
        }

        if (mb_strlen($passphrase) < (int) config('backup.min_passphrase_length')) {
            $this->error('Backup not run: BACKUP_PASSPHRASE must be at least '.config('backup.min_passphrase_length').' characters. Nothing was dumped or sent.');

            return self::FAILURE;
        }

        $files = [];

        try {
            $now = CarbonImmutable::now('Asia/Hebron');
            $filename = 'healthylife-'.$now->format('Ymd-Hi').'.sql.gz.enc';

            $sql = $files[] = $this->tempFile();
            $gz = $files[] = $this->tempFile();
            $enc = $files[] = $this->tempFile();

            $tables = $dumper->dump($sql);
            $gzBytes = $this->gzip($sql, $gz);

            $encrypted = $crypto->encrypt((string) file_get_contents($gz), $passphrase);

            if (strlen($encrypted) > (int) config('backup.max_upload_bytes')) {
                throw new BackupException('upload', 'The encrypted backup is larger than Telegram allows ('.Number::fileSize((int) config('backup.max_upload_bytes')).').');
            }

            file_put_contents($enc, $encrypted);
            unset($encrypted);

            $hash = hash_file('sha256', $enc);
            $caption = "HealthyLife database backup\nDate: ".$now->format('Y-m-d H:i')." (Asia/Hebron)\nCompressed size: ".Number::fileSize($gzBytes)."\nTables: {$tables}\nSHA-256: {$hash}";

            $uploader->send($enc, $filename, $caption, $token, $chatId);

            $scheduler->markRan('backup');
            $scheduler->recordResult('backup', ['ok' => 1, 'tables' => $tables, 'compressed_bytes' => $gzBytes, 'encrypted_bytes' => (int) filesize($enc)]);
            $this->info("Backup sent: {$filename} ({$tables} tables, ".Number::fileSize($gzBytes).' compressed).');

            return self::SUCCESS;
        } catch (Throwable $e) {
            // A BackupException carries only a stage and a message this code wrote; anything
            // else is reduced to its class name, so no event or log line holds data or a secret.
            $safe = $e instanceof BackupException ? $e : new BackupException('unexpected', 'Unexpected failure ('.class_basename($e).').');

            report($safe);
            $scheduler->recordResult('backup', ['ok' => 0, 'failed_stage' => $safe->stage]);
            $this->error("Backup failed at {$safe->stage}: {$safe->getMessage()}");

            return self::FAILURE;
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    private function tempFile(): string
    {
        $dir = config('backup.temp_dir') ?: sys_get_temp_dir();
        $path = tempnam($dir, 'hlbk');

        if ($path === false) {
            throw new BackupException('prepare', 'A temporary file could not be created.');
        }

        return $path;
    }

    /** @return int bytes of the gzip written */
    private function gzip(string $from, string $to): int
    {
        $in = fopen($from, 'rb');
        $out = gzopen($to, 'wb9');

        if ($in === false || $out === false) {
            throw new BackupException('compress', 'The dump could not be compressed.');
        }

        try {
            while (! feof($in)) {
                gzwrite($out, (string) fread($in, 1 << 20));
            }
        } finally {
            fclose($in);
            gzclose($out);
        }

        clearstatcache(true, $to);

        return (int) filesize($to);
    }
}
