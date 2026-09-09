<?php

namespace App\Http\Controllers\Api\Foods;

use App\Http\Controllers\Controller;
use App\Http\Requests\Foods\SearchFoodsRequest;
use App\Http\Requests\Foods\SubmitFoodRequest;
use App\Http\Resources\FoodResource;
use App\Models\Food;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
            ->orderBy('name_en')
            ->paginate($request->integer('per_page', 20));

        return FoodResource::collection($foods);
    }

    /** BR-5: enters `status = 'pending'` — never publicly searchable until an admin approves it. */
    public function store(SubmitFoodRequest $request): JsonResponse
    {
        $food = Food::create([
            ...$request->validated(),
            'source' => 'nutritionist',
            'status' => 'pending',
            'submitted_by' => $request->user()->id,
        ]);

        return (new FoodResource($food))->response()->setStatusCode(201);
    }

    /** Admin review queue — nothing else surfaces a pending submission for review otherwise. */
    public function pending(): AnonymousResourceCollection
    {
        $foods = Food::query()
            ->where('status', 'pending')
            ->latest()
            ->paginate(20);

        return FoodResource::collection($foods);
    }

    public function approve(Food $food): FoodResource
    {
        $food->update(['status' => 'approved']);

        return new FoodResource($food);
    }

    public function reject(Food $food): FoodResource
    {
        $food->update(['status' => 'rejected']);

        return new FoodResource($food);
    }
}
