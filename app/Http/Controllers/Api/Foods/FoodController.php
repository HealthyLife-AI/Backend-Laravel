<?php

namespace App\Http\Controllers\Api\Foods;

use App\Http\Controllers\Controller;
use App\Http\Requests\Foods\SearchFoodsRequest;
use App\Http\Requests\Foods\SubmitFoodRequest;
use App\Http\Resources\FoodResource;
use App\Models\Food;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;

/**
 * FR-25: search the approved food database in Arabic and English.
 * S3-05/FR-24/BR-5: nutritionist submission + admin approve/reject.
 */
class FoodController extends Controller
{
    public function search(SearchFoodsRequest $request): AnonymousResourceCollection
    {
        $foods = Food::query()
            ->approved()
            ->search(trim($request->string('q')))
            ->when($request->boolean('economical'), fn ($q) => $q->where('cost_tier', 'economical'))
            ->when($request->boolean('quick_prep'), fn ($q) => $q->whereIn('prep_level', ['no_cook', 'easy']))
            ->paginate($request->integer('per_page', 20));

        return FoodResource::collection($foods);
    }

    /** BR-5: enters `status = 'pending'` — never publicly searchable until an admin approves it. */
    public function store(SubmitFoodRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $food = Food::create([
            ...Arr::except($validated, ['glycemic_index', 'sodium_mg_per_100g', 'nutrition_review_source']),
            'source' => 'nutritionist',
            'status' => 'pending',
            'submitted_by' => $request->user()->id,
        ]);

        // Suggested values: stored on the pending food, never stamped as reviewed (that is the admin's).
        $suggested = Arr::only($validated, ['glycemic_index', 'sodium_mg_per_100g', 'nutrition_review_source']);
        if ($suggested !== []) {
            $food->forceFill($suggested)->save();
        }

        return (new FoodResource($food))->response()->setStatusCode(201);
    }

    /** The caller's own submissions, newest first, with their review status. */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $foods = Food::query()
            ->where('submitted_by', $request->user()->id)
            ->latest()
            ->paginate(20);

        return FoodResource::collection($foods);
    }

    /** Admin review queue — nothing else surfaces a pending submission for review otherwise. */
    public function pending(): AnonymousResourceCollection
    {
        $foods = Food::query()
            ->where('status', 'pending')
            ->with('nutritionReviewer')
            ->latest()
            ->paginate(20);

        return FoodResource::collection($foods);
    }

    /**
     * Approving never waits for missing fields (the resource says what is missing). A glycemic
     * index or sodium value a nutritionist suggested and nobody has reviewed is stamped with
     * the approver: approving it is the review.
     */
    public function approve(Request $request, Food $food): FoodResource
    {
        $food->status = 'approved';

        if (($food->glycemic_index !== null || $food->sodium_mg_per_100g !== null) && $food->nutrition_reviewed_by === null) {
            $food->forceFill(['nutrition_reviewed_at' => now(), 'nutrition_reviewed_by' => $request->user()->id]);
        }

        $food->save();

        return new FoodResource($food->load('nutritionReviewer'));
    }

    public function reject(Food $food): FoodResource
    {
        $food->update(['status' => 'rejected']);

        return new FoodResource($food);
    }
}
