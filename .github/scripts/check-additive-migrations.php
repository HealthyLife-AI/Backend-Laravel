<?php

/**
 * Fails when a migration added by this PR does something in up() that is not
 * additive: drops, renames, column type changes (->change()) or raw ALTER/DROP.
 * Production holds live data and Dokku runs `migrate --force` on release, so
 * only new tables, nullable columns and indexes are allowed (audit rule 3).
 *
 * Usage: php check-additive-migrations.php <file>...
 * A heuristic, not a parser: a reviewed exception can carry the marker
 * "audit:allow-non-additive" on the offending line.
 */
$forbidden = [
    '/->\s*change\s*\(/' => 'column type change (->change())',
    '/\bdrop[A-Za-z]*\s*\(/' => 'drop',
    '/\brename[A-Za-z]*\s*\(/' => 'rename',
    '/Schema::\s*(drop|dropIfExists|rename)\b/' => 'table drop/rename',
    '/\b(ALTER|DROP|TRUNCATE|RENAME)\s+(TABLE|COLUMN|INDEX)\b/i' => 'raw DDL',
];

// Allowed but worth a reviewer's look: a new enum column can never gain a value additively.
$warn = [
    '/->\s*(enum|set)\s*\(/' => 'enum/set column: adding a value later is an enum edit, which is not additive',
];

$failed = false;

foreach (array_slice($argv, 1) as $file) {
    $src = (string) file_get_contents($file);

    // The body of up(): from "function up" to "function down" (or the end of the file).
    if (! preg_match('/function\s+up\s*\(.*?(?=function\s+down\s*\(|\z)/s', $src, $m)) {
        fwrite(STDERR, "{$file}: no up() found\n");
        $failed = true;

        continue;
    }

    foreach (explode("\n", $m[0]) as $i => $line) {
        if (str_contains($line, 'audit:allow-non-additive')) {
            continue;
        }
        foreach ($forbidden as $re => $what) {
            if (preg_match($re, $line)) {
                echo "::error file={$file}::{$what} in up(): ".trim($line)."\n";
                $failed = true;
            }
        }
        foreach ($warn as $re => $what) {
            if (preg_match($re, $line)) {
                echo "::warning file={$file}::{$what}: ".trim($line)."\n";
            }
        }
    }
}

exit($failed ? 1 : 0);
