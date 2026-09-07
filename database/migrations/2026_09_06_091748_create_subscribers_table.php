<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `subscribers` is the client's DOMAIN profile — goal, per-nutritionist
     * client code, activation status — kept separate from `users` (the
     * AUTH row: email/password/role) so client-specific business data
     * doesn't crowd a table every role shares. `user_id` links the two.
     *
     * `nutritionist_id` also exists here (duplicating `users.nutritionist_id`
     * from Sprint 1) because BR-1 names it explicitly as
     * `subscribers.nutritionist_id`, and every Sprint 2+ client-data table
     * (health_profiles, body_composition_readings, ...) hangs off
     * `subscribers`, not `users` — this is the column
     * `BelongsToNutritionist` (Sprint 1) scopes against for this table and
     * everything that references it.
     */
    public function up(): void
    {
        Schema::create('subscribers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('nutritionist_id')->constrained('users')->cascadeOnDelete();

            // Short, human-shareable identifier shown in the UI (PRD F-2
            // "code" — e.g. "PT-104"), unique per nutritionist rather than
            // globally so each nutritionist's numbering can start at 1.
            $table->string('code', 20);

            $table->enum('goal', [
                'weight_loss',
                'weight_gain',
                'weight_maintenance',
                'health_monitoring',
            ]);

            // Invite-link lifecycle only (FR-02/FR-03). Not a general
            // "deactivate a client" feature — no task in this sprint asks
            // for one, so it isn't built.
            $table->enum('status', ['pending', 'active'])->default('pending');

            // Adherence signal: null until real logging exists (Sprint 4+
            // per the original Milestones; not part of this sprint's
            // scope). Populated later from plan-vs-actual comparison —
            // never fabricated here.
            $table->enum('adherence_status', ['on_track', 'needs_attention', 'late'])->nullable();
            $table->timestamp('last_logged_at')->nullable();

            $table->timestamps();

            $table->unique(['nutritionist_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscribers');
    }
};
