<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4, C: one daily sleep / stress / mood entry per patient. `log_date` is the
 * day the patient wakes up on (the night is "last night" for that date).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_wellbeing_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained()->cascadeOnDelete();
            $table->date('log_date');
            $table->decimal('sleep_hours', 3, 1)->nullable();
            // good | ok | poor
            $table->string('sleep_quality', 8)->nullable();
            // low | medium | high
            $table->string('stress', 8)->nullable();
            // good | ok | low
            $table->string('mood', 8)->nullable();
            $table->string('note', 280)->nullable();
            $table->timestamps();

            $table->unique(['subscriber_id', 'log_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_wellbeing_logs');
    }
};
