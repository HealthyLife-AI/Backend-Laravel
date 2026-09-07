<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One table for all three sources (PRD F-8): USDA base import, the
     * admin-curated Arabic layer, and nutritionist submissions — `source`
     * + `status` distinguish them rather than three separate tables, since
     * they're all just "a food with macros" queried the same way.
     * `status` is BR-5: nutritionist submissions start `pending`; USDA and
     * admin entries are pre-approved.
     *
     * Search uses plain BTREE indexes + a `LIKE 'query%'` (prefix) scan
     * (see FoodController), not FULLTEXT: InnoDB's default
     * `ft_min_word_len` is 4, which would silently drop matches for
     * common 3-letter Arabic food words (أرز، لحم) — a correctness bug
     * that's worse than FULLTEXT's speed advantage is worth. A prefix
     * index also matches actual search-as-you-type UX better than
     * natural-language FULLTEXT ranking would for a short food name.
     */
    public function up(): void
    {
        Schema::create('foods', function (Blueprint $table) {
            $table->id();
            $table->enum('source', ['usda', 'admin', 'nutritionist']);
            $table->enum('status', ['approved', 'pending', 'rejected'])->default('pending');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name_en')->nullable();
            $table->string('name_ar')->nullable();
            $table->unsignedInteger('usda_fdc_id')->nullable()->unique();

            $table->decimal('calories_per_100g', 6, 1);
            $table->decimal('protein_g_per_100g', 5, 1);
            $table->decimal('carbs_g_per_100g', 5, 1);
            $table->decimal('fat_g_per_100g', 5, 1);
            $table->decimal('fiber_g_per_100g', 5, 1)->nullable();

            $table->timestamps();

            $table->index('name_en');
            $table->index('name_ar');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('foods');
    }
};
