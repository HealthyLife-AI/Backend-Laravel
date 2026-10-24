<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained()->cascadeOnDelete();
            $table->date('due_on');
            // pending | submitted | expired
            $table->string('status', 16)->default('pending');
            $table->json('answers')->nullable();
            // The config/checkin.php version the answers were validated against.
            $table->unsignedSmallInteger('questionnaire_version')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->boolean('flagged')->default(false);
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['subscriber_id', 'due_on']);
            $table->index(['subscriber_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_checkins');
    }
};
