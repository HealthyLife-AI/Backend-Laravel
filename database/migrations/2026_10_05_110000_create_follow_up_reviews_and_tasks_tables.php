<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Follow-up between sessions: what the nutritionist writes for the
     * patient (a rating, a note, key points) and the tasks under it. The
     * patient acknowledges a review («فهمت») and ticks tasks done; the
     * nutritionist sees both. Everything cascades with the patient.
     */
    public function up(): void
    {
        Schema::create('follow_up_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('subscribers')->cascadeOnDelete();
            $table->foreignId('nutritionist_id')->constrained('users')->cascadeOnDelete();
            $table->enum('rating', ['on_track', 'small_adjustment', 'review_together'])->nullable();
            $table->text('note')->nullable();
            $table->json('key_points')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            $table->index(['subscriber_id', 'created_at']);
        });

        Schema::create('follow_up_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('follow_up_reviews')->cascadeOnDelete();
            $table->foreignId('subscriber_id')->constrained('subscribers')->cascadeOnDelete();
            $table->string('title', 200);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_tasks');
        Schema::dropIfExists('follow_up_reviews');
    }
};
