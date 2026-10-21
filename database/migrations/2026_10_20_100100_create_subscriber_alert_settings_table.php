<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-patient alert thresholds. Every column is an OVERRIDE: NULL means
     * "use the global default" (config/adherence.php, config/alerts.php),
     * so a row with all NULLs is the same as no row, and changing a global
     * default still reaches every patient who has not been given their own.
     *
     * A diabetic patient who must log every day wants no_log_days = 1; a
     * stable maintenance patient is fine at 5.
     */
    public function up(): void
    {
        Schema::create('subscriber_alert_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->unique()->constrained('subscribers')->cascadeOnDelete();
            $table->unsignedTinyInteger('no_log_days')->nullable();
            $table->unsignedTinyInteger('calorie_exceeded_streak_days')->nullable();
            $table->decimal('milestone_weight_change_kg', 4, 1)->nullable();
            $table->unsignedSmallInteger('milestone_window_days')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriber_alert_settings');
    }
};
