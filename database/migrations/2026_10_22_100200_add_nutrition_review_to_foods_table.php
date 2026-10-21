<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foods', function (Blueprint $table) {
            // No values are filled by the app: they come later from a published
            // table a nutritionist has reviewed (foods:import-nutrition-review).
            $table->unsignedTinyInteger('glycemic_index')->nullable();
            $table->decimal('sodium_mg_per_100g', 7, 1)->nullable();
            $table->string('nutrition_review_source')->nullable();
            $table->timestamp('nutrition_reviewed_at')->nullable();
            $table->unsignedBigInteger('nutrition_reviewed_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('foods', function (Blueprint $table) {
            $table->dropColumn(['glycemic_index', 'sodium_mg_per_100g', 'nutrition_review_source', 'nutrition_reviewed_at', 'nutrition_reviewed_by']);
        });
    }
};
