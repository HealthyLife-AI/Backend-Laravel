<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A food's cost tier in one country. foods.cost_tier stays the 'PS' value; a row
 * here is the value for its own country (and, for 'PS', overrides foods.cost_tier).
 * No row = unknown there: another country never inherits the PS value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('food_regional_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('food_id')->constrained('foods')->cascadeOnDelete();
            $table->char('country', 2);
            // economical | standard | premium
            $table->string('cost_tier', 16);
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['food_id', 'country']);
            $table->index(['country', 'cost_tier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('food_regional_costs');
    }
};
