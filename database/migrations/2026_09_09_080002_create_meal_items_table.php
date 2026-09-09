<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * BR-4 (binding — PRD §5.2/§6, SRS §2.4): an alternative is a meal
     * item that references its main item via `parent_item_id`; a planned
     * item has none. (The Sprint 3 tracker's task description calls this
     * "alt_group" — looser task-list phrasing for the same mechanism, not
     * a different one; PRD/SRS are the binding documents when the two
     * disagree, per the PRD's own change-control rule.)
     *
     * `quantity_grams`, not a generic quantity+unit pair: every food's
     * macros are stored per-100g (`foods.calories_per_100g`, ...), so a
     * gram quantity is what every calculation in
     * `MealPlanCalculatorService` actually needs — no unit-conversion
     * step, no ambiguity about what "quantity: 2" of a food means.
     */
    public function up(): void
    {
        Schema::create('meal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_id')->constrained('meals')->cascadeOnDelete();
            $table->foreignId('food_id')->constrained('foods');
            $table->foreignId('parent_item_id')->nullable()->constrained('meal_items')->cascadeOnDelete();

            $table->decimal('quantity_grams', 6, 1);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['meal_id', 'parent_item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meal_items');
    }
};
