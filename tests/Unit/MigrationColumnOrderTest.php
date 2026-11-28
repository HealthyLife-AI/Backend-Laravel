<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every `->after('column')` in a migration must name a column that an EARLIER
 * migration (by file name, the order `migrate` runs them on a fresh database)
 * has already added to that table.
 *
 * SQLite ignores `after()`, so the suite cannot catch this on its own; MySQL
 * fails with "Unknown column" and a fresh install stops half-way (audit F-DB-01).
 * Production only escaped it because the later-dated migration happened to be
 * deployed first. This reads the migration files statically, so it runs on
 * any engine and in milliseconds.
 */
class MigrationColumnOrderTest extends TestCase
{
    /** Blueprint helpers that add columns without naming them in the first argument. */
    private const IMPLICIT_COLUMNS = [
        'id' => ['id'],
        'timestamps' => ['created_at', 'updated_at'],
        'timestampsTz' => ['created_at', 'updated_at'],
        'nullableTimestamps' => ['created_at', 'updated_at'],
        'softDeletes' => ['deleted_at'],
        'softDeletesTz' => ['deleted_at'],
        'rememberToken' => ['remember_token'],
    ];

    public function test_after_only_names_columns_that_already_exist(): void
    {
        $files = glob(dirname(__DIR__, 2).'/database/migrations/*.php');
        sort($files);

        /** @var array<string, array<string, true>> $columns table => columns added so far */
        $columns = [];
        $problems = [];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            // Split the file into Schema::create/table('name', ...) blocks.
            preg_match_all("/Schema::(create|table)\\(\\s*'(\\w+)'/", $source, $blocks, PREG_OFFSET_CAPTURE);

            foreach ($blocks[2] as $i => [$table, $offset]) {
                $end = $blocks[0][$i + 1][1] ?? strlen($source);
                $body = substr($source, $offset, $end - $offset);

                // Only up(): stop at down() if it falls inside this block.
                $downAt = strpos($body, 'function down');
                if ($downAt !== false) {
                    $body = substr($body, 0, $downAt);
                }

                // Walk the block in order: an after() may reference a column added
                // earlier in the same block, never one added later.
                preg_match_all("/\\\$table->(\\w+)\\(\\s*(?:'(\\w+)')?|->after\\(\\s*'(\\w+)'\\s*\\)/", $body, $tokens, PREG_SET_ORDER);

                foreach ($tokens as $token) {
                    if (! empty($token[3])) {
                        if (! isset($columns[$table][$token[3]])) {
                            $problems[] = basename($file).": ->after('{$token[3]}') on `{$table}`, but no earlier migration adds that column";
                        }

                        continue;
                    }

                    $method = $token[1];
                    foreach (self::IMPLICIT_COLUMNS[$method] ?? [] as $implicit) {
                        $columns[$table][$implicit] = true;
                    }
                    if (! empty($token[2])) {
                        $columns[$table][$token[2]] = true;
                    }
                }
            }
        }

        $this->assertSame([], $problems, "On a fresh MySQL database these migrations fail:\n".implode("\n", $problems));
    }
}
