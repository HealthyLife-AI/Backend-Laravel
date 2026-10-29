<?php

namespace App\Services\Backup;

use App\Exceptions\Backup\BackupException;
use Ifsnop\Mysqldump\Mysqldump;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Pure-PHP SQL dump (structure + data) of one configured connection: the app
 * container has no mysqldump binary. MySQL / MariaDB get a single-transaction dump in
 * utf8mb4, so a running app is not blocked and the dump is one consistent snapshot.
 * Other drivers are refused: the library's SQLite adapter is a stub that cannot dump.
 */
class DatabaseDumper
{
    /** @return int the number of tables dumped */
    public function dump(string $path, ?string $connection = null): int
    {
        $connection ??= (string) config('database.default');
        $config = (array) config("database.connections.{$connection}");

        $dumper = $this->dumper($config);

        try {
            $dumper->start($path);

            return count(Schema::connection($connection)->getTableListing());
        } catch (Throwable $e) {
            // The class only: a driver message can carry the host or the database name.
            throw new BackupException('dump', 'The database dump failed ('.class_basename($e).').');
        }
    }

    /** @param  array<string, mixed>  $config */
    private function dumper(array $config): Mysqldump
    {
        $driver = $config['driver'] ?? null;

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new BackupException('dump', 'Unsupported database driver.');
        }

        $dsn = isset($config['unix_socket']) && $config['unix_socket'] !== ''
            ? "mysql:unix_socket={$config['unix_socket']};dbname={$config['database']}"
            : "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}";

        return new Mysqldump(
            $dsn,
            (string) ($config['username'] ?? ''),
            (string) ($config['password'] ?? ''),
            [
                'default-character-set' => Mysqldump::UTF8MB4,
                'single-transaction' => true,
                'lock-tables' => false,
                'add-drop-table' => true,
                'add-locks' => false,
                'extended-insert' => true,
                'hex-blob' => true,
                'routines' => false,
            ],
            (array) ($config['options'] ?? []),
        );
    }
}
