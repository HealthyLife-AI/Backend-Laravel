<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per subscriber (1:1) — the "current" profile. Point-in-time
     * body-composition history lives separately in
     * `body_composition_readings`; this table holds the profile as it
     * stands today plus its derived `daily_calorie_needs`.
     *
     * `health_conditions` / `medications` / `allergies` / `food_preferences`
     * are JSON arrays rather than child tables: they're short, unstructured
     * lists edited as a whole from one form (PRD F-3), not independently
     * queried, filtered, or joined against elsewhere — a child table per
     * list would only add joins with no matching benefit.
     *
     * `surgery_history` / `lab_notes` / `nutritionist_notes` come from the
     * MVP spec's early nutritionist interview (§0: patient file must
     * include medications, surgeries, and lab results), not the PRD's
     * condensed F-3 summary — the fuller, validated source wins.
     */
    public function up(): void
    {
        Schema::create('health_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->unique()->constrained('subscribers')->cascadeOnDelete();

            $table->decimal('weight_kg', 5, 2);
            $table->decimal('height_cm', 5, 1);
            $table->unsignedTinyInteger('age');
            $table->enum('gender', ['male', 'female']);

            // TDEE multiplier tier (BR-7: Mifflin-St Jeor) — see
            // NutritionCalculatorService for the standard factor per tier.
            $table->enum('activity_level', ['sedentary', 'light', 'moderate', 'active', 'very_active']);

            $table->json('health_conditions')->nullable();
            $table->json('medications')->nullable();
            $table->json('allergies')->nullable();
            $table->json('food_preferences')->nullable();
            $table->text('surgery_history')->nullable();
            $table->text('lab_notes')->nullable();
            $table->text('nutritionist_notes')->nullable();

            // Cached, not computed on every read — recalculated by
            // NutritionCalculatorService whenever the inputs above change
            // (FR-11). Nullable only until the first save.
            $table->unsignedInteger('daily_calorie_needs')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('health_profiles');
    }
};
