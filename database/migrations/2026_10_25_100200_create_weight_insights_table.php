<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3, C: the explanation of a weight change between two readings,
 * generated once per reading pair and cached. Whether the patient sees it is
 * the nutritionist's call, per patient (default off).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weight_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained()->cascadeOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->json('facts');
            $table->string('text_ar', 500);
            $table->boolean('is_fallback')->default(false);
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['subscriber_id', 'to_date']);
        });

        Schema::table('subscribers', function (Blueprint $table) {
            $table->boolean('show_weight_insight')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->dropColumn('show_weight_insight');
        });

        Schema::dropIfExists('weight_insights');
    }
};
