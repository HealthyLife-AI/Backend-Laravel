<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminFoodRequest;
use App\Http\Resources\FoodResource;
use App\Models\Food;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * The admin side of the food catalog: browse everything (any status or
 * source), add foods that go live immediately, correct any food's names
 * or macros, and delete foods nothing uses yet. Review of nutritionist
 * submissions stays on FoodController (pending/approve/reject).
 */
class AdminFoodController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:approved,pending,rejected'],
            'source' => ['nullable', 'in:usda,admin,nutritionist'],
        ]);

        $term = trim($validated['q'] ?? '');

        $foods = Food::query()
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['source'] ?? null, fn ($q, $source) => $q->where('source', $source))
            ->when($term !== '', fn ($q) => $q->search($term), fn ($q) => $q->latest('id'))
            ->paginate(25);

        return FoodResource::collection($foods);
    }

    public function store(AdminFoodRequest $request): JsonResponse
    {
        $food = Food::create([
            ...$request->validated(),
            'source' => 'admin',
            'status' => 'approved',
            'submitted_by' => $request->user()->id,
        ]);

        return (new FoodResource($food))->response()->setStatusCode(201);
    }

    public function update(AdminFoodRequest $request, Food $food): FoodResource
    {
        $food->update($request->validated());

        return new FoodResource($food);
    }

    /**
     * `meal_items.food_id` and `meal_logs.food_id` restrict deletion (a
     * plan or a client's history would otherwise lose the food it points
     * at), so a food already in use is refused with a clear message
     * instead of a database error.
     */
    public function destroy(Food $food): JsonResponse
    {
        $inUse = DB::table('meal_items')->where('food_id', $food->id)->exists()
            || DB::table('meal_logs')->where('food_id', $food->id)->exists();

        if ($inUse) {
            return response()->json([
                'message' => 'This food is used in a meal plan or a client log and cannot be deleted.',
                'code' => 'food_in_use',
            ], 409);
        }

        $food->delete();

        return response()->json(null, 204);
    }
}
