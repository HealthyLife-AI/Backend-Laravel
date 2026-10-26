<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4, A: medical reports a patient uploads for their nutritionist (behind
 * config/medical_reports.php `enabled`).
 *
 * The file itself lives on a private disk, encrypted, under a random path;
 * this row holds its metadata. `path` is the storage path, itself encrypted.
 * A patient's delete is a soft delete; the file is purged 30 days later
 * (`purged_at`), the row kept as the audit trail until the patient's account
 * is deleted (everything cascades then).
 *
 * `report_access_logs`: one row per time a file is streamed (who, when, from
 * where). Never the contents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            // lab_test | imaging | prescription | medical_letter | other
            $table->string('category', 20);
            $table->string('title', 120);
            $table->date('report_date')->nullable();
            $table->string('mime', 60);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedSmallInteger('pages')->nullable();
            // Encrypted with the app key (Crypt::encryptString); never the original file name.
            $table->text('path');
            $table->string('sha256', 64);
            $table->text('nutritionist_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['subscriber_id', 'created_at']);
        });

        Schema::create('report_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('patient_reports')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 20);
            $table->string('ip', 45)->nullable();
            $table->timestamp('accessed_at');

            $table->index(['report_id', 'accessed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_access_logs');
        Schema::dropIfExists('patient_reports');
    }
};
