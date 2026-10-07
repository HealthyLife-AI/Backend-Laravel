<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Appointments between a patient and their nutritionist.
     *
     * Availability is weekly windows (on 15-minute boundaries) and days off,
     * in config('scheduling.timezone'). Every booked appointment holds the
     * 15-minute cells it covers in appointment_holds; the unique index on
     * (nutritionist_id, slot_start) makes a double booking impossible even
     * for two requests at the same instant. Holds are deleted when the
     * appointment is cancelled or moved.
     */
    public function up(): void
    {
        Schema::table('nutritionist_profiles', function (Blueprint $table) {
            $table->string('meeting_link', 300)->nullable()->after('reply_hours');
        });

        Schema::create('nutritionist_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nutritionist_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 0 = Sunday .. 6 = Saturday
            $table->string('start_time', 5);
            $table->string('end_time', 5);
            $table->timestamps();
        });

        Schema::create('nutritionist_days_off', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nutritionist_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->timestamps();
            $table->unique(['nutritionist_id', 'date']);
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('subscribers')->cascadeOnDelete();
            $table->foreignId('nutritionist_id')->constrained('users')->cascadeOnDelete();
            $table->enum('type', ['follow_up', 'results_review', 'quick_consult']);
            $table->enum('channel', ['whatsapp', 'phone', 'video']);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->enum('status', ['booked', 'cancelled', 'completed', 'no_show'])->default('booked');
            $table->json('topics')->nullable();
            $table->string('note', 300)->nullable();
            $table->enum('cancelled_by', ['patient', 'nutritionist', 'system'])->nullable();
            $table->string('cancel_reason', 300)->nullable();
            $table->timestamps();

            $table->index(['nutritionist_id', 'starts_at']);
            $table->index(['subscriber_id', 'status', 'starts_at']);
        });

        Schema::create('appointment_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained('appointments')->cascadeOnDelete();
            $table->foreignId('nutritionist_id')->constrained('users')->cascadeOnDelete();
            $table->dateTime('slot_start');
            $table->unique(['nutritionist_id', 'slot_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_holds');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('nutritionist_days_off');
        Schema::dropIfExists('nutritionist_availability');
        Schema::table('nutritionist_profiles', function (Blueprint $table) {
            $table->dropColumn('meeting_link');
        });
    }
};
