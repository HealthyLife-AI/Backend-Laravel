<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * S5-06 / FR-22: where the client's device push token lives.
     *
     * Not in SRS §2.4's users field list (id, name, email, password,
     * phone, locale) — a genuine addition, flagged per project
     * convention. On `users` rather than a new table: one token per
     * signed-in device session is the FCM model this project needs (a
     * client re-registering on a new device simply overwrites the old
     * token), not a history of every device ever used.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('fcm_token')->nullable()->after('locked_until');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('fcm_token');
        });
    }
};
