<?php

namespace App\Services\Notifications;

use App\Exceptions\Notifications\PushNotificationException;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * S5-06 / FR-22: push notifications via Firebase Cloud Messaging's HTTP v1
 * API, authenticated as the service account with a self-signed JWT
 * exchanged for a short-lived OAuth2 access token — the standard Google
 * server-to-server flow.
 *
 * No new Composer dependency: `firebase/php-jwt` is already installed for
 * this project's OWN JWT auth (Sprint 1) and signs RS256 just as readily
 * as the HS256 it's used for elsewhere; the rest is two HTTP calls this
 * codebase already knows how to make. Pulling in the full
 * `kreait/firebase-php` SDK for one endpoint would be exactly the
 * unnecessary dependency the project avoided when it built
 * `OpenAiCompatibleClient` by hand instead of an LLM SDK.
 */
class FcmPushService
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const CACHE_KEY = 'fcm_access_token';

    public function isConfigured(): bool
    {
        if (filled(config('firebase.credentials_json_base64')) || filled(config('firebase.credentials_json'))) {
            return true;
        }

        $path = config('firebase.credentials_path');

        return filled($path) && is_file($path);
    }

    /**
     * @param  array<string, string>  $data
     *
     * @throws PushNotificationException
     */
    public function send(string $deviceToken, string $title, string $body, array $data = []): void
    {
        if (! $this->isConfigured()) {
            throw new PushNotificationException('Firebase is not configured.');
        }

        $credentials = $this->credentials();

        try {
            $response = Http::withToken($this->accessToken($credentials))
                ->post("https://fcm.googleapis.com/v1/projects/{$credentials['project_id']}/messages:send", [
                    'message' => [
                        'token' => $deviceToken,
                        'notification' => ['title' => $title, 'body' => $body],
                        'data' => $data,
                    ],
                ]);
        } catch (Throwable $e) {
            throw new PushNotificationException('Could not reach FCM.', previous: $e);
        }

        if (! $response->successful()) {
            throw new PushNotificationException("FCM returned HTTP {$response->status()}: {$response->body()}");
        }
    }

    /**
     * Precedence matches config/firebase.php's docblock: base64 > raw
     * JSON > file path — the base64 form is the one this project actually
     * needed after a raw-JSON value broke Taqat's own env-var storage
     * (its unescaped `"` characters corrupted something before the app
     * ever saw an exception to log).
     *
     * @return array<string, mixed>
     */
    private function credentials(): array
    {
        if (filled(config('firebase.credentials_json_base64'))) {
            $decodedBase64 = base64_decode((string) config('firebase.credentials_json_base64'), true);

            if ($decodedBase64 === false) {
                throw new PushNotificationException('FIREBASE_CREDENTIALS_JSON_BASE64 is not valid base64.');
            }

            $raw = $decodedBase64;
        } elseif (filled(config('firebase.credentials_json'))) {
            $raw = config('firebase.credentials_json');
        } else {
            $raw = file_get_contents(config('firebase.credentials_path'));
        }

        $decoded = json_decode((string) $raw, true);

        if (! is_array($decoded) || ! isset($decoded['project_id'], $decoded['client_email'], $decoded['private_key'], $decoded['token_uri'])) {
            throw new PushNotificationException('Firebase credentials are malformed.');
        }

        return $decoded;
    }

    /**
     * Cached across calls: an access token is valid for the hour Google
     * issues it for, so a batch job sending to hundreds of clients (S5-06)
     * exchanges one JWT assertion instead of one per recipient.
     *
     * @param  array<string, mixed>  $credentials
     */
    private function accessToken(array $credentials): string
    {
        return Cache::remember(self::CACHE_KEY, 3000, function () use ($credentials) {
            $now = time();

            $assertion = JWT::encode([
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => $credentials['token_uri'],
                'iat' => $now,
                'exp' => $now + 3600,
            ], $credentials['private_key'], 'RS256');

            $response = Http::asForm()->post($credentials['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

            if (! $response->successful()) {
                throw new PushNotificationException("Could not obtain an FCM access token: HTTP {$response->status()}.");
            }

            $token = $response->json('access_token');

            if (! is_string($token) || $token === '') {
                throw new PushNotificationException('FCM token endpoint returned no access_token.');
            }

            return $token;
        });
    }
}
