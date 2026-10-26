<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4, A4: a second kind of consent beside the privacy policy: the one for
 * uploading medical reports. Every existing row is a privacy-policy consent
 * (the default); the (user, version) unique key is unchanged, so a medical
 * consent's version string is its own (config medical_reports.consent.version).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_consents', function (Blueprint $table) {
            $table->string('type', 24)->default('privacy')->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('patient_consents', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
