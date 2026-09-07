<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Refresh tokens are opaque, single-use, and rotate on every use
     * (FR-05: "securely refresh the authentication token"). Only the
     * SHA-256 hash is stored — the plaintext token is returned to the
     * client once and never persisted, mirroring how `password` is
     * handled. `revoked_at` supports rotation (the old token is revoked
     * the instant a new one is issued from it) and reuse-detection (a
     * revoked token presented again indicates theft, so the whole family
     * is revoked).
     */
    public function up(): void
    {
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('replaced_by_id')
                ->nullable()
                ->constrained('refresh_tokens')
                ->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
    }
};
