<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BR-15: when the PATIENT last changed an entry after first saving it —
     * a PATCH to a meal log, or re-sending a reading's day with different
     * figures. Its own column rather than `updated_at`, which also moves for
     * reasons that are not the patient's edit (a plan edit nulling
     * meal_item_id, the late-mark backfill), so the nutritionist's "edited"
     * marker means exactly one thing.
     *
     * Nullable and not backfilled: nothing recorded which past updates were
     * patient edits, and guessing from updated_at would mark rows the
     * patient never touched.
     */
    public function up(): void
    {
        Schema::table('meal_logs', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable()->after('is_late');
        });
        Schema::table('body_composition_readings', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable()->after('is_late');
        });
    }

    public function down(): void
    {
        Schema::table('meal_logs', function (Blueprint $table) {
            $table->dropColumn('edited_at');
        });
        Schema::table('body_composition_readings', function (Blueprint $table) {
            $table->dropColumn('edited_at');
        });
    }
};
