<?php

namespace App\Models;

use App\Models\Concerns\BelongsToNutritionist;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Auth;

/**
 * The client's domain profile (PRD F-2/F-3) — goal, per-nutritionist code,
 * activation/adherence status. Auth (email/password/role) lives on the
 * linked `User` (Sprint 1); this table is everything about the client
 * that's specific to being someone's nutrition-practice client, not
 * specific to being an authenticated user.
 *
 * `use`s `BelongsToNutritionist` (scaffolded in Sprint 1 for exactly this
 * moment): every EXPLICIT query (`Subscriber::query()`, a relation like
 * `$user->subscribers()`) is automatically scoped to the current
 * nutritionist's own subscribers (BR-2/NFR-12), and `nutritionist_id`
 * auto-fills from the authenticated nutritionist on create.
 *
 * That scope does NOT reliably protect implicit route-model-bound
 * parameters (`Route::get('clients/{subscriber}', ...)` type-hinted as
 * `Subscriber $subscriber`): Laravel resolves the binding via
 * `SubstituteBindings`, which runs before the app's custom `jwt`
 * middleware sets the authenticated user — the scope sees no user yet
 * and (correctly, per its own contract) skips filtering rather than
 * silently locking everyone out. Every controller that receives a
 * route-bound `Subscriber` MUST additionally call `belongsToCaller()`
 * and 404 if false — see ClientController::show for the pattern.
 */
#[Fillable(['user_id', 'nutritionist_id', 'code', 'goal', 'status'])]
class Subscriber extends Model
{
    use BelongsToNutritionist, HasFactory;

    protected function casts(): array
    {
        return [
            'last_logged_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function healthProfile(): HasOne
    {
        return $this->hasOne(HealthProfile::class);
    }

    public function bodyCompositionReadings(): HasMany
    {
        return $this->hasMany(BodyCompositionReading::class)->orderByDesc('recorded_at');
    }

    public function invites(): HasMany
    {
        return $this->hasMany(ClientInvite::class);
    }

    public function mealPlans(): HasMany
    {
        return $this->hasMany(MealPlan::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * True if this subscriber belongs to the currently authenticated
     * nutritionist. Required belt-and-suspenders check for any
     * route-model-bound `Subscriber` — see the class docblock for why
     * the global scope alone doesn't cover that case.
     */
    public function belongsToCaller(): bool
    {
        return $this->nutritionist_id === Auth::id();
    }
}
