<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * True while the patient still uses the password the system generated
     * (on create or on a reset by the nutritionist); false once they set
     * their own with PUT /me/password. The app shows a gentle reminder;
     * nothing is forced.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('password_is_temporary')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('password_is_temporary');
        });
    }
};
