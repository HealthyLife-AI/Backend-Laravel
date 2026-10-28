<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per response of the AI provider (a failed JSON-mode attempt and its plain-text
 * retry are two rows): the token counts the provider reported, and nothing else. No prompt,
 * no reply, no health data. Cost is computed at read time from config prices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            // ai_draft | plan_summary | weekly_summary | weight_insight | ping | other
            $table->string('feature', 32);
            // Who it was for. Kept (set null) when either is deleted: the cost happened.
            $table->foreignId('nutritionist_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('subscriber_id')->nullable()->constrained('subscribers')->nullOnDelete();
            $table->string('model', 100);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            // Part of completion_tokens (reasoning models), and part of prompt_tokens, when the provider says so.
            $table->unsignedInteger('reasoning_tokens')->nullable();
            $table->unsignedInteger('cached_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->default(0);
            // 1 = the first request of the call, 2 = the plain-text retry after the provider refused JSON mode.
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->boolean('success');
            $table->unsignedInteger('latency_ms')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['nutritionist_id', 'created_at']);
            $table->index(['feature', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
