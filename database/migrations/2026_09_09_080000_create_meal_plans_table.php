<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * S3-01 / FR-12: a plan is either assigned to one client
     * (`subscriber_id` set) or a reusable template the nutritionist owns
     * directly (`subscriber_id` null, `is_template` true) — SRS §2.4's
     * field list for this table. A template has no subscriber to scope
     * through, so isolation for templates goes via `created_by` instead
     * (see `MealPlan::belongsToCaller()`).
     *
     * `status` distinguishes drafts (including AI drafts — BR-6/BR-10)
     * from the one plan a client can currently see (`active`) from
     * superseded history (`archived`). Only `activate()` — the same
     * explicit action for a hand-built plan or an AI draft — ever moves a
     * plan to `active`; nothing else does, structurally enforcing BR-6
     * ("never auto-sent to a client").
     */
    public function up(): void
    {
        Schema::create('meal_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->nullable()->constrained('subscribers')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            $table->boolean('is_template')->default(false);
            $table->boolean('is_ai_draft')->default(false);
            $table->date('start_date')->nullable();
            $table->enum('status', ['draft', 'active', 'archived'])->default('draft');

            $table->timestamps();

            $table->index(['subscriber_id', 'status']);
            $table->index(['created_by', 'is_template']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meal_plans');
    }
};
