<?php

namespace App\Http\Controllers\Api\Foods;

use App\Http\Controllers\Controller;
use App\Http\Requests\Foods\SearchFoodsRequest;
use App\Http\Requests\Foods\SubmitFoodRequest;
use App\Http\Resources\FoodResource;
use App\Models\Food;
use App\Services\Foods\FoodRegionalCostService;
use App\Support\RegionalCountry;
use Illuminate\Validation\Rule;
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
            // The caller's country (a patient's nutritionist's): a query, so pagination counts the right rows.
            ->when($request->boolean('economical'), fn ($q) => $q->costTierIn(RegionalCountry::current(), ['economical']))
            ->when($request->boolean('quick_prep'), fn ($q) => $q->whereIn('prep_level', ['no_cook', 'easy']))
            ->paginate($request->integer('per_page', 20));

        return FoodResource::collection($foods);
    }

    /** BR-5: enters `status = 'pending'` — never publicly searchable until an admin approves it. */
    public function store(SubmitFoodRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $country = RegionalCountry::of($request->user());

        // A submitted cost is the submitter's country's: foods.cost_tier is the PS value, so only a PS nutritionist writes it.
        $regionalCost = $country === RegionalCountry::default() ? null : ($validated['cost_tier'] ?? null);

        $food = Food::create([
            ...Arr::except($validated, ['glycemic_index', 'sodium_mg_per_100g', 'nutrition_review_source', ...($country === RegionalCountry::default() ? [] : ['cost_tier'])]),
            'source' => 'nutritionist',
            'status' => 'pending',
            'submitted_by' => $request->user()->id,
        ]);

        if ($regionalCost !== null) {
            app(FoodRegionalCostService::class)->set($food, $country, $regionalCost, $request->user());
        }

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

    /**
     * The nutritionist's cost tier for a food in THEIR OWN country: it applies at once (a cost is not
     * clinical), is recorded with who set it, and null clears it. A country is never accepted from the
     * caller. For a PS nutritionist this writes a regional 'PS' row, which overrides foods.cost_tier for PS.
     */
    public function setCost(Request $request, Food $food): JsonResponse
    {
        abort_if($food->status === 'rejected', 404);

        $data = $request->validate([
            'cost_tier' => ['present', 'nullable', Rule::in(Food::COST_TIERS)],
            'country' => ['prohibited'],
        ]);

        $country = RegionalCountry::of($request->user());
        app(FoodRegionalCostService::class)->set($food, $country, $data['cost_tier'], $request->user());

        return response()->json(['food_id' => $food->id, 'country' => $country, 'cost_tier' => $data['cost_tier']]);
    }

    /** Admin review queue — nothing else surfaces a pending submission for review otherwise. */
    public function pending(): AnonymousResourceCollection
    {
        $foods = Food::query()
            ->where('status', 'pending')
            ->with(['nutritionReviewer', 'allRegionalCosts.setter'])
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

        return new FoodResource($food->load(['nutritionReviewer', 'allRegionalCosts.setter']));
    }

    public function reject(Food $food): FoodResource
    {
        $food->update(['status' => 'rejected']);

        return new FoodResource($food);
    }
}
