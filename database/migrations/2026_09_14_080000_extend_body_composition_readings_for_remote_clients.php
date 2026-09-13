<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * S4-16 / FR-29, BR-11, BR-13: let a remotely managed client record
     * the measurements they can genuinely take alone.
     *
     * The split is by instrument, not by trust: waist, hip, thigh and arm
     * need a tape measure, so a remote client can take them; body-fat
     * percentage, muscle mass and water percentage need a bio-impedance
     * analyser and stay nutritionist-only (BR-11).
     *
     * `source` exists because the two kinds of reading end up in one
     * clinical series. BR-13 requires a nutritionist to always be able to
     * tell which figures are analyser-grade and which are the client's own
     * estimate, so the distinction is stored per row rather than inferred
     * from which endpoint happened to write it.
     */
    public function up(): void
    {
        Schema::table('body_composition_readings', function (Blueprint $table) {
            $table->enum('source', ['clinic-analyser', 'self-reported'])
                ->default('clinic-analyser')
                ->after('recorded_at');

            $table->decimal('hip_cm', 5, 1)->nullable()->after('waist_cm');
            $table->decimal('thigh_cm', 5, 1)->nullable()->after('hip_cm');
            $table->decimal('arm_cm', 5, 1)->nullable()->after('thigh_cm');
        });

        // Every row that already exists predates the client-facing
        // endpoint — until this migration there was no way for a client to
        // write a reading at all, so all of them arrived through the
        // nutritionist's endpoint during a visit. Stated explicitly rather
        // than left to the column default, so the assumption behind the
        // backfill is on the record and not merely implied by a DDL line.
        DB::table('body_composition_readings')->update(['source' => 'clinic-analyser']);
    }

    public function down(): void
    {
        Schema::table('body_composition_readings', function (Blueprint $table) {
            $table->dropColumn(['source', 'hip_cm', 'thigh_cm', 'arm_cm']);
        });
    }
};
