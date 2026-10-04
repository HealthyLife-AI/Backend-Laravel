<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The patient's notification inbox and their notification settings.
     *
     * An inbox row is kept for every notification, whether or not it was
     * pushed (quiet hours, category switched off, no device): push_skipped
     * says why not. Texts are stored rendered in the patient's locale and
     * are deliberately generic: no note, weight or other health detail.
     * dedupe_key lets a burst of events (activate, then edit a plan) update
     * one row instead of adding more.
     */
    public function up(): void
    {
        Schema::create('patient_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('category', ['meals', 'measurements', 'nutritionist', 'plan', 'system']);
            $table->string('type', 50);
            $table->string('title', 200);
            $table->string('body', 500);
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('pushed_at')->nullable();
            $table->string('push_skipped', 30)->nullable();
            $table->string('dedupe_key', 100)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'dedupe_key']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('locale', 5)->default('ar');
            $table->boolean('meals')->default(true);
            $table->boolean('measurements')->default(true);
            $table->boolean('nutritionist')->default(true);
            $table->boolean('plan')->default(true);
            $table->boolean('quiet_hours_enabled')->default(true);
            $table->string('quiet_start', 5)->default('22:00');
            $table->string('quiet_end', 5)->default('07:00');
            $table->string('timezone', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('patient_notifications');
    }
};
