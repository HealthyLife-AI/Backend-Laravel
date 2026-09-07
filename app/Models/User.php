<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * A single `users` table backs every role — nutritionist, client, and
 * admin (PRD §2.1) — distinguished by the Spatie role assigned to the
 * record, not by separate tables. `nutritionist_id` is only ever set on
 * client-role users and is the FK the Sprint 2 client/health-profile
 * models will inherit their data-isolation scope from
 * (see App\Models\Scopes\NutritionistScope).
 */
#[Fillable(['name', 'email', 'phone', 'password', 'nutritionist_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'locked_until' => 'datetime',
        ];
    }

    /**
     * The nutritionist this user (a client) belongs to. Null for
     * nutritionist- and admin-role users.
     */
    public function nutritionist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'nutritionist_id');
    }

    /**
     * The clients belonging to this user (only meaningful when this user
     * holds the `nutritionist` role).
     */
    public function clients(): HasMany
    {
        return $this->hasMany(User::class, 'nutritionist_id');
    }

    /**
     * This user's own client/domain profile (Sprint 2) — only meaningful
     * when this user holds the `client` role.
     */
    public function subscriberProfile(): HasOne
    {
        return $this->hasOne(Subscriber::class);
    }

    /**
     * The domain profiles of this nutritionist's clients (only meaningful
     * when this user holds the `nutritionist` role). Prefer this over
     * `clients()` for anything that needs client-specific data (goal,
     * status, health profile, ...) — `clients()` only gives you the bare
     * auth row.
     */
    public function subscribers(): HasMany
    {
        return $this->hasMany(Subscriber::class, 'nutritionist_id');
    }

    /**
     * Non-expired, unused refresh tokens issued to this user.
     */
    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    /**
     * Whether the account is currently locked out (FR-04).
     */
    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /**
     * Record a failed login attempt and lock the account once the
     * configured threshold (default 5) is reached.
     */
    public function registerFailedLogin(): void
    {
        $maxAttempts = config('jwt.max_login_attempts');

        $this->failed_login_attempts++;

        if ($this->failed_login_attempts >= $maxAttempts) {
            $this->locked_until = now()->addMinutes(config('jwt.lockout_minutes'));
        }

        $this->save();
    }

    /**
     * Clear the failed-attempt counter and any lockout after a
     * successful login.
     */
    public function resetFailedLogins(): void
    {
        if ($this->failed_login_attempts !== 0 || $this->locked_until !== null) {
            $this->forceFill([
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();
        }
    }
}
