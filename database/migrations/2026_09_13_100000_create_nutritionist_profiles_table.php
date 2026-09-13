<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * S4-00 / PRD §5.2: the nutritionist's own professional details.
     *
     * The SRS has documented this table since early design; a Sprint 4
     * audit found it had never been built. Created now rather than when
     * billing arrives, so the column billing will read already exists and
     * the gap cannot silently reopen.
     *
     * `plan_tier` is a plain string, deliberately NOT an enum. The tier
     * names appear in the PRD only inside §8 "Open Decisions" — Basic
     * (~20 USD) and Professional (~40 USD) are a first interview signal
     * the PRD itself says is "worth re-examining once more data arrives",
     * and billing is Post-MVP. An enum would freeze an explicitly
     * unfrozen product decision into the schema and need a migration to
     * change. The allowed values are enforced one layer up
     * (NutritionistProfile::TIERS) where changing them costs nothing.
     *
     * One row per nutritionist, so `user_id` is unique — this is a
     * one-to-one extension of `users`, not a history table.
     */
    public function up(): void
    {
        Schema::create('nutritionist_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            $table->string('specialty')->nullable();
            $table->string('clinic_name')->nullable();
            $table->string('bio', 1000)->nullable();
            $table->string('plan_tier')->default('basic');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nutritionist_profiles');
    }
};
