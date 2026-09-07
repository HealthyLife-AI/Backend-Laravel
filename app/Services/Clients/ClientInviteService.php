<?php

namespace App\Services\Clients;

use App\Exceptions\Auth\InvalidInviteTokenException;
use App\Models\ClientInvite;
use App\Models\Subscriber;
use Illuminate\Support\Str;

/**
 * Issues and consumes single-use client invite links (FR-02/FR-03, BR-3),
 * following the same hash-and-rotate discipline as Sprint 1's
 * RefreshTokenService: only a SHA-256 hash is ever stored, the plaintext
 * is handed back once (to build the WhatsApp link) and never persisted.
 */
class ClientInviteService
{
    /**
     * @return array{plain: string, model: ClientInvite}
     */
    public function issue(Subscriber $subscriber): array
    {
        $plain = Str::random(40);

        $model = $subscriber->invites()->create([
            'token_hash' => $this->hash($plain),
            'expires_at' => now()->addDays(config('invite.ttl_days')),
        ]);

        return ['plain' => $plain, 'model' => $model];
    }

    /**
     * Validate and consume an invite token, returning the invite (with
     * its subscriber loaded) so the caller can activate the account.
     * Marks it used — a token is single-use regardless of outcome once
     * this returns successfully.
     *
     * @throws InvalidInviteTokenException
     */
    public function consume(string $plainToken): ClientInvite
    {
        $invite = ClientInvite::query()
            ->with('subscriber')
            ->where('token_hash', $this->hash($plainToken))
            ->first();

        if ($invite === null) {
            throw new InvalidInviteTokenException;
        }

        if ($invite->isUsed()) {
            throw new InvalidInviteTokenException('This invite link has already been used.');
        }

        if ($invite->isExpired()) {
            throw new InvalidInviteTokenException('This invite link has expired.');
        }

        $invite->forceFill(['used_at' => now()])->save();

        return $invite;
    }

    private function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
