<?php

namespace Tests\Concerns;

use Illuminate\Database\Migrations\Migration;

/**
 * For tests that prove a migration's backfill and its rollback: load the
 * migration by file name, roll it back with `down()`, plant legacy rows,
 * run `up()` and assert what the backfill did. The test must leave the
 * schema exactly as it found it (`up()` again in a `finally`), because on
 * MySQL DDL commits the surrounding test transaction.
 */
trait ExercisesMigrations
{
    protected function migrationFile(string $name): Migration
    {
        return require database_path("migrations/{$name}.php");
    }
}
