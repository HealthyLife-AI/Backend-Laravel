<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            // The check-in a checkin_flag alert is about; reviewing it resolves exactly this alert.
            $table->foreignId('checkin_id')->nullable()->after('quick_request_id')
                ->constrained('patient_checkins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('checkin_id');
        });
    }
};
