<?php

namespace App\Http\Controllers\Api\MealPlans;

use App\Http\Controllers\Controller;
use App\Http\Resources\MealPlanResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * S3-04 / FR-16, plans.view.own: the client's own current plan. No
 * route-bound ID at all — the authenticated client's own `Subscriber`
 * row is resolved from their JWT, not supplied by the caller, so there's
 * nothing here for one client to point at another client's data with in
 * the first place (stronger than a `belongsToCaller()` check on a
 * caller-supplied ID: it removes the ID).
 */
class ClientPlanController extends Controller
{
    public function show(): MealPlanResource|Response
    {
        $subscriber = Auth::user()->subscriberProfile;

        $plan = $subscriber?->mealPlans()->where('status', 'active')->first();

        if ($plan === null) {
            return response()->noContent(); // 204: no plan assigned yet, not an error
        }

        return new MealPlanResource($plan->load(['meals.items.food', 'meals.items.alternatives.food']));
    }
}
