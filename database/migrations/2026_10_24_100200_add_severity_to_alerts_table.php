<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alerts gain a severity ('normal' | 'important') and two new types
 * (measurement_overdue, quick_request).
 *
 * `alerts.type` was created as an ENUM of the three original types. Adding
 * values to an ENUM is a MySQL-only ALTER that SQLite (the test database)
 * cannot do, and an enum would need another migration for every future
 * type. So the column is converted ONCE to a plain string: existing rows
 * keep their values (a type change, not a data rewrite), the type set then
 * lives in App\Models\Alert, and validation stays in the application, which
 * is where every other status/type column of this schema already keeps it.
 *
 * Guarded: a database whose `type` is already a string is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->string('severity', 16)->default('normal')->after('type');
        });

        // MySQL: the column is an ENUM. SQLite: a varchar carrying a CHECK of
        // the three old values. Either rejects the new types, so both are
        // converted; a column that is already a plain string is left alone.
        $type = collect(Schema::getColumns('alerts'))->firstWhere('name', 'type');

        if ($type !== null && (str_starts_with((string) $type['type'], 'enum') || DB::getDriverName() === 'sqlite')) {
            Schema::table('alerts', function (Blueprint $table) {
                $table->string('type', 40)->change();
            });
        }

        Schema::table('alerts', function (Blueprint $table) {
            // The dashboard lists important first, then newest.
            $table->index(['severity', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropIndex(['severity', 'created_at']);
            $table->dropColumn('severity');
        });
    }
};
