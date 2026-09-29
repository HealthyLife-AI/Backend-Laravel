<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A patient's acceptance of the privacy / data-processing policy (BR-17):
     * which version, when, and from where (IP and user agent), kept as the
     * record that consent was given.
     *
     * One row per (patient, version): accepting the same version twice is
     * the same acceptance, and a new policy version is a new row, so the
     * history of what each patient agreed to and when is never overwritten.
     * Cascades with the user, so deleting a patient's account deletes their
     * consent record with everything else about them.
     */
    public function up(): void
    {
        Schema::create('patient_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('version', 64);
            $table->timestamp('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_consents');
    }
};
