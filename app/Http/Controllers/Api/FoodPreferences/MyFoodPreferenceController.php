<?php

namespace App\Http\Controllers\Api\FoodPreferences;

use App\Http\Controllers\Controller;
use App\Http\Requests\FoodPreferences\UpdateFoodPreferencesRequest;
use App\Models\PatientFoodPreference;
use App\Models\Subscriber;
use App\Services\FoodPreferences\FoodPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 2, B2: the patient's own likes and dislikes. No route-bound id: the
 * subscriber comes from the token.
 */
class MyFoodPreferenceController extends Controller
{
    public function __construct(private readonly FoodPreferenceService $preferences) {}

    public function show(): JsonResponse
    {
        return response()->json($this->preferences->lists($this->subscriber()));
    }

    /** Replaces the patient's OWN rows; the nutritionist's are never touched. */
    public function update(UpdateFoodPreferencesRequest $request): JsonResponse
    {
        $subscriber = $this->subscriber();

        $this->preferences->replace($subscriber, $request->validated('likes'), $request->validated('dislikes'), PatientFoodPreference::SOURCE_PATIENT);

        return response()->json($this->preferences->lists($subscriber));
    }

    private function subscriber(): Subscriber
    {
        $subscriber = Auth::user()->subscriberProfile;

        abort_if($subscriber === null, 403, 'This account is not set up as a client.');

        return $subscriber;
    }
}
