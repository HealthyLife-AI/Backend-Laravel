<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the patient app shows about "my nutritionist": how to address
     * them (gender, for Arabic grammar) and how to reach them
     * (`whatsapp_number`, E.164 with the leading +).
     *
     * Both are nullable and nothing is backfilled: nobody has told us these
     * for existing nutritionists, and guessing a gender from a name is not
     * something to do to a real person. The dashboard prompts them to fill
     * the two in.
     */
    public function up(): void
    {
        Schema::table('nutritionist_profiles', function (Blueprint $table) {
            $table->enum('gender', ['male', 'female'])->nullable()->after('clinic_name');
            $table->string('whatsapp_number', 20)->nullable()->after('gender');
        });
    }

    public function down(): void
    {
        Schema::table('nutritionist_profiles', function (Blueprint $table) {
            $table->dropColumn(['gender', 'whatsapp_number']);
        });
    }
};
