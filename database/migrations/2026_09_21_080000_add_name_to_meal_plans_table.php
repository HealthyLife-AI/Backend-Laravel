<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A template with no name reads only as "3 meals · 876 kcal" — two
     * templates built for different clinical purposes but similar in
     * size are indistinguishable on the Plans page (Frontend, S3-03
     * follow-up). Nullable and free-text: a hand-built client plan
     * doesn't need one (it has exactly one audience and one context —
     * the client it belongs to), only a template genuinely benefits from
     * being named, and there's nothing to validate a nutritionist's own
     * label against.
     */
    public function up(): void
    {
        Schema::table('meal_plans', function (Blueprint $table) {
            $table->string('name')->nullable()->after('is_ai_draft');
        });
    }

    public function down(): void
    {
        Schema::table('meal_plans', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
