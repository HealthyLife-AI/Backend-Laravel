<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * S5-02 / FR-20: rule-based proactive alerts raised for a client and
     * shown to their nutritionist.
     *
     * `resolved_at` is NOT in the SRS §2.4 field list (subscriber_id,
     * type, message, is_read, created_at only) — a deliberate deviation,
     * flagged here per project convention.
     *
     * Two of the three FR-20 rules describe an ongoing CONDITION (no log
     * for N days; calories over target for N days running), not a
     * one-off event. Without a resolved state, the daily evaluation job
     * would either re-create a duplicate alert every single day the
     * condition persists, or (if deduplicated on type alone) never be
     * able to re-fire once the condition recurs after genuinely
     * clearing. `is_read` cannot carry this meaning — it tracks whether
     * the NUTRITIONIST has seen the alert, which is unrelated to whether
     * the underlying condition is still true. `resolved_at` is a second,
     * independent axis: the engine opens one alert when a condition
     * starts, leaves it open (no spam) while the condition persists, and
     * closes it the moment the condition stops holding.
     */
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('subscribers')->cascadeOnDelete();

            $table->enum('type', ['no_log', 'calories_exceeded', 'milestone']);
            $table->string('message');
            $table->boolean('is_read')->default(false);
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            // The evaluation job's own query: "is there already an open
            // alert of this type for this client?", run once per
            // subscriber per type on every scheduled pass.
            $table->index(['subscriber_id', 'type', 'resolved_at']);
            // The nutritionist's alert list: unread-first, newest-first.
            $table->index(['subscriber_id', 'is_read']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
