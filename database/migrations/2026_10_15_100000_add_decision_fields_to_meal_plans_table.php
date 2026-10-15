<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Item C: the nutritionist's own decision about a plan, kept apart
     * from the formula.
     *
     *  - `approved_calories`: the target she actually prescribed. The
     *    Mifflin-St Jeor figure in `health_profiles.daily_calorie_needs`
     *    stays exactly as it is and keeps being recomputed on save — it
     *    becomes the SUGGESTION, and this becomes the target once set
     *    (see App\Services\Nutrition\CalorieTarget).
     *  - `suggested_calories`: what the formula said at the moment the
     *    plan was activated. Frozen for the history, so a later profile
     *    edit can't rewrite what she was deciding against at the time.
     *  - `plan_summary` / `summary_updated_at`: her written reasoning.
     *    Sensitive health data and nutritionist-only — never in a client
     *    response, a log line or Sentry.
     *
     * All nullable: every plan written before this existed simply has no
     * decision recorded, which is the truth about it.
     */
    public function up(): void
    {
        Schema::table('meal_plans', function (Blueprint $table) {
            $table->unsignedSmallInteger('approved_calories')->nullable()->after('status');
            $table->unsignedSmallInteger('suggested_calories')->nullable()->after('approved_calories');
            $table->text('plan_summary')->nullable()->after('suggested_calories');
            $table->timestamp('summary_updated_at')->nullable()->after('plan_summary');
        });
    }

    public function down(): void
    {
        Schema::table('meal_plans', function (Blueprint $table) {
            $table->dropColumn(['approved_calories', 'suggested_calories', 'plan_summary', 'summary_updated_at']);
        });
    }
};
