<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Patients sign in with a username the nutritionist chooses (replaces
     * the invite link). Unique across ALL users because login is global;
     * nullable because nutritionists keep their e-mail and patients created
     * before this change get one at their first password reset. Stored
     * already normalised (App\Support\Username).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 30)->nullable()->unique()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
