<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Access tokens are stateless JWTs, so revoking refresh tokens alone
     * leaves an issued access token working until it expires. Each access
     * token carries the user's session version (`sv`); ending all sessions
     * bumps the version, and JwtAuthenticate refuses any other value. A
     * counter rather than a timestamp, so a token issued in the same second
     * as the revocation is told apart exactly.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('session_version')->default(0)->after('locked_until');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('session_version');
        });
    }
};
