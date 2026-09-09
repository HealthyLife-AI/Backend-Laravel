<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A meal slot within a plan (PRD F-4: breakfast/snack/lunch/dinner).
     * `day_index` (0 = Monday .. 6 = Sunday) is nullable and optional —
     * null means this meal repeats every day (a daily plan, the MVP's
     * common case); set, it belongs to one day of a weekly plan (PRD F-4
     * "daily/weekly plan"). Both shapes share the same three tables
     * rather than needing a separate `days` table.
     */
    public function up(): void
    {
        Schema::create('meals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_plan_id')->constrained('meal_plans')->cascadeOnDelete();

            $table->enum('name', ['breakfast', 'snack', 'lunch', 'dinner']);
            $table->unsignedTinyInteger('day_index')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['meal_plan_id', 'day_index', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meals');
    }
};
