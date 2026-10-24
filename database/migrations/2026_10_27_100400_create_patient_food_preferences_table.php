<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_food_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained()->cascadeOnDelete();
            $table->foreignId('food_id')->constrained('foods')->cascadeOnDelete();
            // like | dislike. Not allergies: those live in patient_allergies.
            $table->string('kind', 8);
            // patient | nutritionist: who added it. A patient's own edits never remove the nutritionist's rows.
            $table->string('source', 16);
            $table->timestamps();

            $table->unique(['subscriber_id', 'food_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_food_preferences');
    }
};
