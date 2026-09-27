<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sign in / sign up with Google for nutritionist accounts.
     *
     *  - `google_id`: Google's stable account id (`sub`), so a person whose
     *    Google e-mail later changes still lands on the same account.
     *    Nullable and unique — password-only accounts never set it.
     *  - `avatar_url`: the profile picture Google hands back, kept for the
     *    dashboard header. Nothing else is stored from the Google profile.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id')->nullable()->unique()->after('email');
            $table->string('avatar_url', 2048)->nullable()->after('google_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
            $table->dropColumn(['google_id', 'avatar_url']);
        });
    }
};
