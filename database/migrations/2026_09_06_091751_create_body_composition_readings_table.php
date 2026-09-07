<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per visit/reading (FR-10: "preserved across visits" — a
     * history, not an overwritten snapshot). Weight is captured per
     * reading too, alongside the composition fields, since the weight
     * trend chart (PRD F-6) plots this same series.
     */
    public function up(): void
    {
        Schema::create('body_composition_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('subscribers')->cascadeOnDelete();
            $table->date('recorded_at');

            $table->decimal('weight_kg', 5, 2);
            $table->decimal('body_fat_percent', 4, 1)->nullable();
            $table->decimal('muscle_mass_kg', 5, 2)->nullable();
            $table->decimal('water_percent', 4, 1)->nullable();
            $table->decimal('waist_cm', 5, 1)->nullable();

            $table->timestamps();

            $table->index(['subscriber_id', 'recorded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('body_composition_readings');
    }
};
