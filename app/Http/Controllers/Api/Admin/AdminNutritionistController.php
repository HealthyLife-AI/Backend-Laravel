<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Every nutritionist account on the platform, with how many clients each has. */
class AdminNutritionistController extends Controller
{
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
            ->latest()
            ->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'clinic_name' => $user->nutritionistProfile?->clinic_name,
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
