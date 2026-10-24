<?php

namespace App\Http\Controllers\Api\FoodPreferences;

use App\Http\Controllers\Controller;
use App\Http\Requests\FoodPreferences\UpdateFoodPreferencesRequest;
use App\Models\PatientFoodPreference;
use App\Models\Subscriber;
use App\Services\FoodPreferences\FoodPreferenceService;
use Illuminate\Http\JsonResponse;

/**
 * Phase 2, B3: the nutritionist's view and edit of one patient's likes and
 * dislikes. What she writes is source=nutritionist and is never removed by
 * the patient's own PUT. Owner only; the write carries the follow-up gate.
 */
class ClientFoodPreferenceController extends Controller
{
    public function __construct(private readonly FoodPreferenceService $preferences) {}

    public function show(Subscriber $subscriber): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        return response()->json($this->preferences->lists($subscriber));
    }

    public function update(Subscriber $subscriber, UpdateFoodPreferencesRequest $request): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        $this->preferences->replace($subscriber, $request->validated('likes'), $request->validated('dislikes'), PatientFoodPreference::SOURCE_NUTRITIONIST);

        return response()->json($this->preferences->lists($subscriber));
    }
}
