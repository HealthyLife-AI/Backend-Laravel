<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            // Only for code "other".
            $table->string('other_text', 100)->nullable();
            $table->string('note', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One row per code per patient; "other" may repeat with different
            // text, so uniqueness for it is checked in HealthRecordService
            // (a unique key cannot express "except other" on MySQL).
            $table->index(['subscriber_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_conditions');
    }
};
