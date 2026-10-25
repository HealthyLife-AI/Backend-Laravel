<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3, A: short education posts ("نصائح أخصائيتك") a nutritionist writes
 * for her own patients: to all of them, to those with one of a set of
 * conditions, or to named patients. Plain text only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nutritionist_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 120);
            $table->text('body');
            // all | conditions | patients
            $table->string('audience', 16)->default('all');
            $table->json('condition_codes')->nullable();
            // draft | published
            $table->string('status', 16)->default('draft');
            $table->boolean('pinned')->default(false);
            // The FIRST publish: later unpublish / publish cycles keep it, and do not push again.
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['author_id', 'status']);
        });

        Schema::create('nutritionist_post_targets', function (Blueprint $table) {
            $table->foreignId('post_id')->constrained('nutritionist_posts')->cascadeOnDelete();
            $table->foreignId('subscriber_id')->constrained()->cascadeOnDelete();

            $table->primary(['post_id', 'subscriber_id']);
        });

        Schema::create('nutritionist_post_reads', function (Blueprint $table) {
            $table->foreignId('post_id')->constrained('nutritionist_posts')->cascadeOnDelete();
            $table->foreignId('subscriber_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');

            $table->primary(['post_id', 'subscriber_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nutritionist_post_reads');
        Schema::dropIfExists('nutritionist_post_targets');
        Schema::dropIfExists('nutritionist_posts');
    }
};
