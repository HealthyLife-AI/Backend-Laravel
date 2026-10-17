<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BMI as the nutritionist entered it at the visit.
     *
     * Stored rather than always derived from weight ÷ height² because the
     * clinic analyser prints its own figure, and that is the number she
     * reads out and writes in the file. The computed value is still
     * offered as a suggestion in the form; this column keeps whichever
     * one she decided on, with no attempt to reconcile the two.
     *
     * Nullable: every reading taken before this column existed, and every
     * self-reported one (BR-11 — a client with a tape measure does not
     * produce a BMI), simply has none.
     */
    public function up(): void
    {
        Schema::table('body_composition_readings', function (Blueprint $table) {
            $table->decimal('bmi', 4, 1)->nullable()->after('water_percent');
        });
    }

    public function down(): void
    {
        Schema::table('body_composition_readings', function (Blueprint $table) {
            $table->dropColumn('bmi');
        });
    }
};
