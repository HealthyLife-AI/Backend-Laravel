<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The country a nutritionist works in (ISO 3166-1 alpha-2), which decides how
 * regional data such as a food's cost tier is read. Every existing user gets 'PS'.
 *
 * On `users`, not `nutritionist_profiles`: that profile row is created lazily on
 * first access (a nutritionist may have none), so a join to it would need a
 * fallback in every query, while the authenticated user is always already
 * loaded. Only a nutritionist's value is ever used (a patient reads their
 * nutritionist's); it is meaningless, and ignored, for patients and admins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('country', 2)->default('PS');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('country');
        });
    }
};
