<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Mirrors `refresh_tokens` (Sprint 1): only the SHA-256 hash of the
     * invite token is stored, the plaintext is returned once (to embed in
     * the WhatsApp link — MVP spec §5.1/BR-3) and never persisted.
     * Single-use via `used_at`, not deleted on use — keeps an audit trail
     * of when a client actually activated.
     */
    public function up(): void
    {
        Schema::create('client_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('subscribers')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['subscriber_id', 'used_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_invites');
    }
};
