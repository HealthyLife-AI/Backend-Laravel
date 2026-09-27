<?php

namespace App\Services\Auth;

use App\Exceptions\Auth\InvalidGoogleTokenException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Continue with Google" for nutritionists.
 *
 * The browser obtains an OAuth access token from Google Identity Services
 * (the token-client flow, so the button can be styled like the rest of the
 * form) and hands it to `POST /auth/google`. This service never trusts the
 * token on its face: it asks Google's `tokeninfo` endpoint who the token
 * was issued to and for which client (`aud`), refuses anything not minted
 * for this app's `GOOGLE_CLIENT_ID` or without a verified e-mail, then
 * reads the profile from `userinfo`.
 *
 * Account matching, in order: by `google_id`, then by verified e-mail (so
 * a nutritionist who registered with a password can also sign in with the
 * Google account of the same address — Google has verified they own it),
 * otherwise a new nutritionist is created with an unusable random
 * password. Client-role accounts are never created here.
 */
class GoogleAuthService
{
    private const TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';

    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

    public function isConfigured(): bool
    {
        return filled(config('services.google.client_id'));
    }

    /**
     * @throws InvalidGoogleTokenException
     */
    public function authenticate(string $accessToken): User
    {
        if (! $this->isConfigured()) {
            throw new InvalidGoogleTokenException('Google sign-in is not configured on this server.');
        }

        $profile = $this->verifiedProfile($accessToken);

        return DB::transaction(function () use ($profile) {
            $user = User::query()->where('google_id', $profile['sub'])->first()
                ?? User::query()->where('email', $profile['email'])->first();

            if ($user === null) {
                $user = User::create([
                    'name' => $profile['name'],
                    'email' => $profile['email'],
                    'google_id' => $profile['sub'],
                    'avatar_url' => $profile['picture'],
                    'password' => Hash::make(Str::random(48)),
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();
                $user->assignRole('nutritionist');

                return $user;
            }

            $user->forceFill(array_filter([
                'google_id' => $user->google_id ?? $profile['sub'],
                'avatar_url' => $profile['picture'] ?? $user->avatar_url,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]))->save();

            return $user;
        });
    }

    /**
     * @return array{sub: string, email: string, name: string, picture: ?string}
     *
     * @throws InvalidGoogleTokenException
     */
    private function verifiedProfile(string $accessToken): array
    {
        try {
            $info = Http::timeout(8)->get(self::TOKENINFO_URL, ['access_token' => $accessToken]);
        } catch (Throwable $e) {
            throw new InvalidGoogleTokenException('Could not reach Google to verify the sign-in.', previous: $e);
        }

        if (! $info->successful()) {
            throw new InvalidGoogleTokenException;
        }

        if ((string) $info->json('aud') !== (string) config('services.google.client_id')) {
            throw new InvalidGoogleTokenException('This Google token was not issued for HealthyLife.');
        }

        if (filter_var($info->json('email_verified'), FILTER_VALIDATE_BOOLEAN) !== true || blank($info->json('email'))) {
            throw new InvalidGoogleTokenException('Your Google account e-mail is not verified.');
        }

        try {
            $userinfo = Http::timeout(8)->withToken($accessToken)->get(self::USERINFO_URL);
        } catch (Throwable $e) {
            throw new InvalidGoogleTokenException('Could not reach Google to read your profile.', previous: $e);
        }

        if (! $userinfo->successful()) {
            throw new InvalidGoogleTokenException;
        }

        $email = Str::lower((string) $info->json('email'));

        return [
            'sub' => (string) ($userinfo->json('sub') ?: $info->json('sub')),
            'email' => $email,
            'name' => (string) ($userinfo->json('name') ?: Str::before($email, '@')),
            'picture' => $userinfo->json('picture') ? (string) $userinfo->json('picture') : null,
        ];
    }
}
