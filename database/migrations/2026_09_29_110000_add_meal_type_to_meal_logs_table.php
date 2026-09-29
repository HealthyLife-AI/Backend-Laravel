<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which meal of the day a log belongs to (BR-16), so the patient app can
     * group a day's history under breakfast / lunch / dinner / snack.
     *
     * Existing on-plan logs are backfilled from the plan meal their
     * `meal_item_id` points at (the same enum as `meals.name`). Existing
     * off-plan logs stay null: nothing on the row says what meal they were.
     * The column is nullable for exactly that reason, and because a plan
     * edit can null `meal_item_id` on old logs (nullOnDelete), which must
     * keep the meal type they were logged with.
     *
     * Portable backfill (a correlated subquery, not UPDATE ... JOIN, which
     * SQLite lacks).
     */
    public function up(): void
    {
        Schema::table('meal_logs', function (Blueprint $table) {
            $table->enum('meal_type', ['breakfast', 'lunch', 'dinner', 'snack'])->nullable()->after('meal_item_id');
        });

        DB::statement(
            'UPDATE meal_logs SET meal_type = ('
            .'SELECT meals.name FROM meal_items JOIN meals ON meals.id = meal_items.meal_id '
            .'WHERE meal_items.id = meal_logs.meal_item_id'
            .') WHERE meal_item_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::table('meal_logs', function (Blueprint $table) {
            $table->dropColumn('meal_type');
        });
    }
};
