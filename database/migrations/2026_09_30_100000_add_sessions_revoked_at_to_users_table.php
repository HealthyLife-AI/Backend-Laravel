<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Access tokens are stateless JWTs, so revoking refresh tokens alone
     * leaves an already-issued access token working until it expires. Any
     * access token issued before this moment is refused (JwtAuthenticate),
     * so a password change or a new sign-in link ends other sessions at once.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('sessions_revoked_at')->nullable()->after('locked_until');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sessions_revoked_at');
        });
    }
};
