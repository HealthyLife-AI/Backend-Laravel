<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a plan actually reached the client.
     *
     * Added because two separate things needed the same missing fact:
     *
     *  1. S4-07's plan-vs-actual chart has to know which days a plan was
     *     in force. Without it the active plan's daily total was applied
     *     to every day in the window, so a plan activated today claimed
     *     the client had been "prescribed" those calories all week and
     *     graded them against a plan that did not exist yet.
     *  2. Professional traceability: `status` says a plan is active but
     *     not since when, and `created_at` is when it was DRAFTED. A
     *     nutritionist reviewing a case — especially an AI-drafted plan
     *     (BR-6) — needs the date they approved it, not the date the
     *     draft was generated.
     *
     * Backfilled from `updated_at` for plans already active: activate()
     * was the last write to those rows in practice, so it is the closest
     * honest approximation available. Anything else (created_at, or
     * leaving it null) would either claim a date we know is wrong or
     * blank out history that is genuinely recoverable. Archived plans are
     * deliberately left null — their `updated_at` is when they were
     * SUPERSEDED, which is the opposite of what this column means.
     */
    public function up(): void
    {
        Schema::table('meal_plans', function (Blueprint $table) {
            $table->timestamp('activated_at')->nullable()->after('status');
        });

        DB::table('meal_plans')
            ->where('status', 'active')
            ->update(['activated_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('meal_plans', function (Blueprint $table) {
            $table->dropColumn('activated_at');
        });
    }
};
