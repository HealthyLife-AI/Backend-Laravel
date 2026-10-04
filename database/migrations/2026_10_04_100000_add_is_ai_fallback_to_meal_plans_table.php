<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which path produced an AI draft: true when the rule-based calorie fit
     * did (no provider, the call failed, or the response failed validation),
     * so the dashboard never labels it a "smart" draft. Like
     * ai_summaries.is_fallback. Existing plans stay false: nothing recorded
     * which path made them.
     */
    public function up(): void
    {
        Schema::table('meal_plans', function (Blueprint $table) {
            $table->boolean('is_ai_fallback')->default(false)->after('is_ai_draft');
        });
    }

    public function down(): void
    {
        Schema::table('meal_plans', function (Blueprint $table) {
            $table->dropColumn('is_ai_fallback');
        });
    }
};
