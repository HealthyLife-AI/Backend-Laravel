<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a log was when the patient made it: the plan's planned item, one
     * of its alternatives, or off-plan. Stored rather than derived from
     * meal_item_id, because a plan edit (swapping a planned item with its
     * alternative) deletes and recreates items and nulls the link on past
     * logs — which used to turn on-plan history into off-plan and lower past
     * adherence after the fact. BR-9/BR-14 count planned + alternative as
     * on-plan, exactly as before.
     *
     * Existing rows are backfilled from their current link: no item is
     * off_plan, an item without a parent is planned, one with a parent is an
     * alternative. Logs whose item was already deleted read off_plan, as they
     * do today.
     */
    public function up(): void
    {
        Schema::table('meal_logs', function (Blueprint $table) {
            $table->enum('log_kind', ['planned', 'alternative', 'off_plan'])->default('off_plan')->after('meal_item_id');
        });

        DB::statement(
            "UPDATE meal_logs SET log_kind = 'planned' WHERE meal_item_id IS NOT NULL AND EXISTS ("
            .'SELECT 1 FROM meal_items WHERE meal_items.id = meal_logs.meal_item_id AND meal_items.parent_item_id IS NULL)'
        );
        DB::statement(
            "UPDATE meal_logs SET log_kind = 'alternative' WHERE meal_item_id IS NOT NULL AND EXISTS ("
            .'SELECT 1 FROM meal_items WHERE meal_items.id = meal_logs.meal_item_id AND meal_items.parent_item_id IS NOT NULL)'
        );
    }

    public function down(): void
    {
        Schema::table('meal_logs', function (Blueprint $table) {
            $table->dropColumn('log_kind');
        });
    }
};
