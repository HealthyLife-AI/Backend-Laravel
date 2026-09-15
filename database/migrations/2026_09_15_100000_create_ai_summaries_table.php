<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * S5-04 / FR-21: the natural-language weekly summary per client.
     *
     * `is_fallback` is NOT in the SRS §2.4 field list (subscriber_id,
     * week_start, summary_text, generated_at) — a deliberate addition,
     * flagged per project convention. S5-05 requires the fallback path
     * to exist and never fail silently; without recording which path
     * produced a given summary, "silently" is exactly what a fallback
     * would be from the nutritionist's side — a real LLM-written
     * assessment and a templated one read identically with nothing
     * distinguishing them. Same reasoning as `is_ai_draft` on meal plans
     * (BR-6/BR-10): the origin of AI-adjacent output is never hidden.
     *
     * One row per (subscriber, week_start) — re-running the job for a
     * week already summarised replaces that week's row rather than
     * appending a second one for the same period.
     */
    public function up(): void
    {
        Schema::create('ai_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('subscribers')->cascadeOnDelete();

            $table->date('week_start');
            $table->text('summary_text');
            $table->boolean('is_fallback')->default(false);
            $table->timestamp('generated_at');

            $table->timestamps();

            $table->unique(['subscriber_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_summaries');
    }
};
