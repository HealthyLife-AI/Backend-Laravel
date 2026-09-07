<?php

namespace App\Http\Controllers\Api\HealthProfiles;

use App\Http\Controllers\Controller;
use App\Http\Requests\HealthProfiles\UpdateHealthProfileRequest;
use App\Http\Resources\HealthProfileResource;
use App\Models\Subscriber;
use App\Services\Nutrition\NutritionCalculatorService;
use Illuminate\Http\Response;

/**
 * FR-07/FR-09/FR-11: the client's health profile. `$subscriber` is
 * route-model-bound but that alone doesn't isolate it — see
 * Subscriber::belongsToCaller() — so every action re-checks ownership
 * explicitly before touching the profile (BR-2/NFR-12).
 */
class HealthProfileController extends Controller
{
    public function __construct(private readonly NutritionCalculatorService $calculator) {}

    public function show(Subscriber $subscriber): HealthProfileResource|Response
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        $profile = $subscriber->healthProfile;

        if ($profile === null) {
            return response()->noContent(); // 204: not created yet, not an error
        }

        return new HealthProfileResource($profile);
    }

    /**
     * Create-or-update (PRD F-3: edited as one form). `daily_calorie_needs`
     * is recalculated here on every save (FR-11) — never trust a stale
     * cached value once any input to the formula changes.
     */
    public function update(Subscriber $subscriber, UpdateHealthProfileRequest $request): HealthProfileResource
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        $data = $request->validated();

        $data['daily_calorie_needs'] = $this->calculator->calculateDailyCalorieNeeds(
            weightKg: (float) $data['weight_kg'],
            heightCm: (float) $data['height_cm'],
            age: (int) $data['age'],
            gender: $data['gender'],
            activityLevel: $data['activity_level'],
        );

        $profile = $subscriber->healthProfile()->updateOrCreate(
            ['subscriber_id' => $subscriber->id],
            $data,
        );

        return new HealthProfileResource($profile);
    }
}
