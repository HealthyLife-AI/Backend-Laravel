<?php

namespace App\Console\Commands\Backup;

use App\Exceptions\Backup\BackupException;
use App\Services\Backup\BackupCrypto;
use Illuminate\Console\Command;

/**
 * Turns a `db:backup` file back into a gzip of the SQL dump, for a restore on any
 * machine that has this app (see docs/backup.md).
 */
class DecryptBackup extends Command
{
    protected $signature = 'db:backup-decrypt {file : The .sql.gz.enc file} {--out= : Where to write the decrypted .sql.gz (default: next to the file, without .enc)} {--force : Overwrite the output file if it exists}';

    protected $description = 'Decrypt a database backup made by db:backup (passphrase from BACKUP_PASSPHRASE, or prompted)';

    public function handle(BackupCrypto $crypto): int
    {
        $file = (string) $this->argument('file');

        if (! is_file($file)) {
            $this->error('File not found.');

            return self::FAILURE;
        }

        $out = (string) ($this->option('out') ?: (str_ends_with($file, '.enc') ? substr($file, 0, -4) : $file.'.dec'));

        if (file_exists($out) && ! $this->option('force')) {
            $this->error("Output file already exists: {$out}. Pass --force to overwrite it.");

            return self::FAILURE;
        }

        $passphrase = (string) config('backup.passphrase');

        if ($passphrase === '') {
            $passphrase = (string) $this->secret('Backup passphrase');
        }

        try {
            $plain = $crypto->decrypt((string) file_get_contents($file), $passphrase);
        } catch (BackupException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        file_put_contents($out, $plain);
        $this->info("Decrypted to {$out}. Next: gunzip it, then import the .sql into an empty MySQL database (see docs/backup.md).");

        return self::SUCCESS;
    }
}
