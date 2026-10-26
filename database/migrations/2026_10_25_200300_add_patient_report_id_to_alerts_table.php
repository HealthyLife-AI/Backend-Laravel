<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            // The report a report_uploaded alert is about; marking it reviewed resolves exactly this alert.
            $table->foreignId('patient_report_id')->nullable()->after('checkin_id')
                ->constrained('patient_reports')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('patient_report_id');
        });
    }
};
