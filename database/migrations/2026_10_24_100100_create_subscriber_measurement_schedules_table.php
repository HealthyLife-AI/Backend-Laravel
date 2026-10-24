<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriber_measurement_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('interval_days');
            $table->date('next_due_on');
            // The last day a "time to measure" push went out, so the daily job
            // never pushes twice for the same due date.
            $table->date('last_reminded_on')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('next_due_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriber_measurement_schedules');
    }
};
