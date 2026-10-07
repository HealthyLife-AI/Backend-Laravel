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

    /**
     * Changing a food that plans or logs already use changes what those
     * plans and logs add up to, so the first attempt is answered 409 with
     * how many use it; the admin UI shows that and resends with
     * `confirm_in_use: true` once the admin agrees.
     */
    public function update(AdminFoodRequest $request, Food $food): JsonResponse|FoodResource
    {
        $usage = $this->usage($food);

        // Only nutrition values affect plans and logs; re-tagging (allergens,
        // shopping section) needs no in-use confirmation.
        $changesValues = (clone $food)->fill(collect($request->validated())->except(['allergens', 'shopping_section'])->all())->isDirty();

        if ($changesValues && ($usage['meal_plans'] > 0 || $usage['meal_logs'] > 0) && ! $request->boolean('confirm_in_use')) {
            return response()->json([
                'message' => 'This food is used in meal plans or client logs. Resend with confirm_in_use to save.',
                'code' => 'food_in_use_confirm',
                'usage' => $usage,
            ], 409);
        }

        $food->update($request->validated());

        return new FoodResource($food);
    }

    /**
     * `meal_items.food_id` and `meal_logs.food_id` restrict deletion (a
     * plan or a client's history would otherwise lose the food it points
     * at), so a food already in use is refused with a clear message
     * instead of a database error — whatever its source.
     *
     * A seeded food (USDA, or one of ArabicFoodSeeder's curated dishes)
     * isn't removed but hidden (`status = rejected`): the row keeps its
     * `usda_fdc_id` / `seed_key`, which is what makes the seeders skip it,
     * so the next seed run doesn't bring it back. Rejected foods are out
     * of search and refused in plans and logs (BR-5). Everything else is
     * deleted outright.
     */
    public function destroy(Food $food): JsonResponse
    {
        $usage = $this->usage($food);

        if ($usage['meal_plans'] > 0 || $usage['meal_logs'] > 0) {
            return response()->json([
                'message' => 'This food is used in a meal plan or a client log and cannot be deleted.',
                'code' => 'food_in_use',
                'usage' => $usage,
            ], 409);
        }

        if ($food->isSeeded()) {
            $food->update(['status' => 'rejected']);
        } else {
            $food->delete();
        }

        return response()->json(null, 204);
    }

    /** @return array{meal_plans: int, meal_logs: int} */
    private function usage(Food $food): array
    {
        return [
            'meal_plans' => DB::table('meal_items')
                ->join('meals', 'meals.id', '=', 'meal_items.meal_id')
                ->where('meal_items.food_id', $food->id)
                ->distinct()
                ->count('meals.meal_plan_id'),
            'meal_logs' => DB::table('meal_logs')->where('food_id', $food->id)->count(),
        ];
    }
}
