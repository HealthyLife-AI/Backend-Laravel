<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * PRD F-1 / MVP spec §5.1: a client is added by name + phone only —
     * no email is collected at creation. `email` must therefore be
     * optional; MySQL allows multiple NULLs under a UNIQUE index, so
     * nullable + unique still correctly rejects two REAL emails
     * colliding. See AuthController::login() — a client with no email
     * authenticates by phone instead.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
