<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * PRD §2.2 / BR-2 / NFR-12 (the platform's non-negotiable security rule):
 * "Permission alone is not security." Every query against a model that
 * carries `nutritionist_id` must be filtered to the current nutritionist's
 * own rows — no raw query may bypass it.
 *
 * This scope is applied automatically by the BelongsToNutritionist trait
 * (App\Models\Concerns\BelongsToNutritionist). It has nothing to attach
 * to yet — Sprint 2 introduces the first models that carry
 * `nutritionist_id` (health profiles, plans, logs) — so it's scaffolded
 * here, exercised by tests, and ready to `use` from day one.
 *
 * Isolation rule applied:
 *  - Nutritionist-role user: sees only rows where nutritionist_id = their
 *    own id.
 *  - Client-role user: sees only rows belonging to their own
 *    nutritionist (nutritionist_id = $user->nutritionist_id). Narrowing
 *    to the client's *own* records on top of that is each model's own
 *    concern (e.g. a further `client_id` filter), not this scope's job.
 *  - Admin-role user: unrestricted — admins curate the shared food
 *    database (F-8) and do not own a client roster.
 *  - No authenticated user (console, queued job, seeder): scope is not
 *    applied. Code running outside a request must pass nutritionist_id
 *    explicitly or wrap the query in `Model::withoutGlobalScope(...)`
 *    deliberately — never silently.
 */
class NutritionistScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if ($user === null || $user->hasRole('admin')) {
            return;
        }

        $nutritionistId = $user->hasRole('nutritionist') ? $user->id : $user->nutritionist_id;

        if ($nutritionistId !== null) {
            $builder->where($model->qualifyColumn('nutritionist_id'), $nutritionistId);
        }
    }
}
