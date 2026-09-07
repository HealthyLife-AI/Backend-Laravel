<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Extends the base `users` table to carry every role (nutritionist,
     * client, admin — PRD §2.1) in a single table, distinguished by Spatie
     * role assignment. Adds:
     *  - `phone`: required for client accounts (PRD F-1), unique per
     *    nutritionist rather than globally (BR-1: a client belongs to
     *    exactly one nutritionist).
     *  - `nutritionist_id`: self-referencing FK, set only for client-role
     *    users. This is the column the Sprint 2 client models will be
     *    scoped by (see App\Models\Scopes\NutritionistScope).
     *  - `failed_login_attempts` / `locked_until`: account lockout after 5
     *    consecutive failed attempts (FR-04).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->foreignId('nutritionist_id')
                ->nullable()
                ->after('phone')
                ->constrained('users')
                ->nullOnDelete();
            $table->unsignedTinyInteger('failed_login_attempts')->default(0)->after('password');
            $table->timestamp('locked_until')->nullable()->after('failed_login_attempts');
        });

        // A client's phone only needs to be unique within their own
        // nutritionist's roster (BR-1), not across the whole platform.
        Schema::table('users', function (Blueprint $table) {
            $table->unique(['nutritionist_id', 'phone'], 'users_nutritionist_id_phone_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_nutritionist_id_phone_unique');
            $table->dropConstrainedForeignId('nutritionist_id');
            $table->dropColumn(['phone', 'failed_login_attempts', 'locked_until']);
        });
    }
};
