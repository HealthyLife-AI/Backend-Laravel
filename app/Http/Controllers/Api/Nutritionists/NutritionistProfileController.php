<?php

namespace App\Http\Controllers\Api\Nutritionists;

use App\Http\Controllers\Controller;
use App\Http\Requests\Nutritionists\UpdateNutritionistProfileRequest;
use App\Http\Resources\NutritionistProfileResource;
use App\Models\NutritionistProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * S4-00 / PRD §5.2: the nutritionist's own professional profile.
 *
 * Resolved from the JWT with no route-bound id, the same shape as the
 * client's own endpoints — there is nothing here for one nutritionist to
 * point at another's profile with.
 */
class NutritionistProfileController extends Controller
{
    /**
     * Status forced to 200. A `JsonResource` wrapping a model that was
     * just created reports 201 on its own, and `profileFor()` creates the
     * row on first access — so without this, simply READING the profile
     * for the first time answers "201 Created" to a GET.
     */
    public function show(): JsonResponse
    {
        return (new NutritionistProfileResource($this->profileFor()))
            ->response()
            ->setStatusCode(200);
    }

    public function update(UpdateNutritionistProfileRequest $request): JsonResponse
    {
        $profile = $this->profileFor();
        $profile->fill($request->validated())->save();

        return (new NutritionistProfileResource($profile))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * Created on first access rather than at registration, so an account
     * that never opens the profile screen carries no empty row — and so
     * every nutritionist who registered before this table existed still
     * gets one without a backfill migration.
     */
    private function profileFor(): NutritionistProfile
    {
        return NutritionistProfile::firstOrCreate(['user_id' => Auth::id()]);
    }
}
