<?php

namespace App\Models\Concerns;

use App\Models\Scopes\NutritionistScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * `use` this trait on every Sprint 2+ model that carries a
 * `nutritionist_id` column (health profiles, plans, logs, alerts, ...).
 * It registers the global data-isolation scope (BR-2 / NFR-12) and
 * auto-fills `nutritionist_id` on create from the authenticated user, so
 * every controller/service gets isolation for free instead of having to
 * remember it per query.
 *
 * The model must have a `nutritionist_id` column pointing at `users.id`.
 */
trait BelongsToNutritionist
{
    protected static function bootBelongsToNutritionist(): void
    {
        static::addGlobalScope(new NutritionistScope);

        static::creating(function (Model $model): void {
            if (filled($model->nutritionist_id) || ! Auth::check()) {
                return;
            }

            $user = Auth::user();
            $model->nutritionist_id = $user->hasRole('nutritionist') ? $user->id : $user->nutritionist_id;
        });
    }

    public function nutritionist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'nutritionist_id');
    }
}
