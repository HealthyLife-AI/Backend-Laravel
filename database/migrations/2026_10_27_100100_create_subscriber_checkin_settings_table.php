<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriber_checkin_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->unique()->constrained()->cascadeOnDelete();
            // 7 or 14; null = switched off (the row is kept so the choice stays visible).
            $table->unsignedSmallInteger('interval_days')->nullable();
            // The next due date; null while off.
            $table->date('next_due_on')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('next_due_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriber_checkin_settings');
    }
};
