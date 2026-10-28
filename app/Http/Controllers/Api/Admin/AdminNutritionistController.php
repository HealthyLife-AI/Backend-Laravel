<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\RegionalCountry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Every nutritionist account on the platform, with how many clients each has. */
class AdminNutritionistController extends Controller
{
    /**
     * The country a nutritionist works in. Admin only: a nutritionist sees theirs (the login / me payload)
     * but has no way to change it, so they cannot pick the regional costs they read.
     */
    public function setCountry(Request $request, User $nutritionist): JsonResponse
    {
        abort_unless($nutritionist->hasRole('nutritionist'), 404);

        $data = $request->validate(['country' => ['required', Rule::in(RegionalCountry::codes())]]);
        $nutritionist->forceFill(['country' => $data['country']])->save();

        return response()->json(['id' => $nutritionist->id, 'country' => $nutritionist->country]);
    }

    public function index(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        $page = User::role('nutritionist')
            ->with('nutritionistProfile')
            ->withCount([
                'subscribers as clients_count' => fn ($q) => $q->withoutGlobalScopes(),
                'subscribers as active_clients_count' => fn ($q) => $q->withoutGlobalScopes()->active(),
            ])
            ->when($term !== '', fn ($q) => $q->where(
                fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")
            ))
            // id breaks created_at ties (rows made in the same second), so
            // paging is stable instead of depending on the engine's order.
            ->latest()
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'countries' => config('countries.list'),
            'data' => collect($page->items())->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'clinic_name' => $user->nutritionistProfile?->clinic_name,
                'country' => $user->country ?: RegionalCountry::default(),
                'clients_count' => (int) $user->clients_count,
                'active_clients_count' => (int) $user->active_clients_count,
                'created_at' => $user->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }
}
