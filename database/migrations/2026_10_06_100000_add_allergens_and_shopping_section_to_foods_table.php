<?php

use App\Support\FoodTagger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allergen groups (a subset of FoodTagger::GROUPS) and the shopping-list
     * section of each food. Existing foods are tagged here: hand-checked tags
     * for the curated Arabic dishes and Arabic-named items
     * (database/data/food_tags_curated.csv), rules for the rest of the USDA
     * catalog (its SR Legacy food category plus name keywords), name rules
     * and section "other" for admin/nutritionist foods. New foods are tagged
     * when created (Food::booted).
     */
    public function up(): void
    {
        Schema::table('foods', function (Blueprint $table) {
            $table->json('allergens')->nullable()->after('fiber_g_per_100g');
            $table->enum('shopping_section', FoodTagger::SECTIONS)->default('other')->after('allergens');
        });

        app(FoodTagger::class)->backfill();
    }

    public function down(): void
    {
        Schema::table('foods', function (Blueprint $table) {
            $table->dropColumn(['allergens', 'shopping_section']);
        });
    }
};
