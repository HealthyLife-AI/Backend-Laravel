<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4, B: how costly a food is and how much preparation it needs. Both are
 * null until a nutritionist has reviewed the food: unknown shows nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foods', function (Blueprint $table) {
            // economical | standard | premium
            $table->string('cost_tier', 16)->nullable();
            // no_cook | easy | normal
            $table->string('prep_level', 16)->nullable();
            $table->timestamp('tags_reviewed_at')->nullable();
            $table->foreignId('tags_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('foods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tags_reviewed_by');
            $table->dropColumn(['cost_tier', 'prep_level', 'tags_reviewed_at']);
        });
    }
};
