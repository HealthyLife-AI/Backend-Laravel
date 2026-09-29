<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A notice to a nutritionist that one of their patients deleted their
     * own account (BR-18). It holds ONLY the patient's code and the date:
     * the account and everything about the patient is gone, and the notice
     * must not keep a name, phone, id or anything else that identifies them
     * beyond the code the nutritionist already knows them by.
     *
     * The nutritionist dismisses it (a hard delete); it also goes with the
     * nutritionist's own account.
     */
    public function up(): void
    {
        Schema::create('patient_deletion_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nutritionist_id')->constrained('users')->cascadeOnDelete();
            $table->string('patient_code', 20);
            $table->timestamp('deleted_at');
            $table->timestamps();

            $table->index(['nutritionist_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_deletion_notices');
    }
};
