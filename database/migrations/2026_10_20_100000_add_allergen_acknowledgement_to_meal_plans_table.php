<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The nutritionist's recorded decision to activate a plan that holds a
     * food conflicting with a CONFIRMED allergy.
     *
     *  - `allergen_ack_at` / `allergen_ack_by`: when, and which user.
     *  - `allergen_ack_snapshot`: the conflicts as they stood at that
     *    moment, kept whole. A later edit, or an allergy approved after
     *    activation, is judged against THIS list: anything not in it is a
     *    new conflict and is flagged again rather than passing silently.
     *
     * All nullable and additive: a plan with no acknowledgement simply has
     * none, which is the truth about every plan written before this.
     * Nutritionist-only; MealPlanResource strips it for the client role.
     */
    public function up(): void
    {
        Schema::table('meal_plans', function (Blueprint $table) {
            $table->timestamp('allergen_ack_at')->nullable()->after('summary_updated_at');
            $table->foreignId('allergen_ack_by')->nullable()->after('allergen_ack_at')->constrained('users')->nullOnDelete();
            $table->json('allergen_ack_snapshot')->nullable()->after('allergen_ack_by');
        });
    }

    public function down(): void
    {
        Schema::table('meal_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('allergen_ack_by');
            $table->dropColumn(['allergen_ack_at', 'allergen_ack_snapshot']);
        });
    }
};
