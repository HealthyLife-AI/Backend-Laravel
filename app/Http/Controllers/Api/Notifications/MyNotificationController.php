<?php

namespace App\Http\Controllers\Api\Notifications;

use App\Http\Controllers\Controller;
use App\Models\PatientNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/** The patient's notification inbox. Only ever their own rows. */
class MyNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['nullable', Rule::in(PatientNotification::CATEGORIES)],
            'unread' => ['nullable', 'boolean'],
        ]);

        $page = $this->mine()
            ->when($data['category'] ?? null, fn ($q, $category) => $q->where('category', $category))
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (PatientNotification $n) => $this->present($n))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function unreadCount(): JsonResponse
    {
        return response()->json(['unread' => $this->mine()->whereNull('read_at')->count()]);
    }

    public function read(string $id): JsonResponse
    {
        $notification = $this->mine()->findOrFail($id);

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return response()->json($this->present($notification));
    }

    public function readAll(): JsonResponse
    {
        $updated = $this->mine()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['marked' => $updated]);
    }

    private function mine()
    {
        return PatientNotification::query()->where('user_id', Auth::id());
    }

    /** @return array<string, mixed> */
    private function present(PatientNotification $n): array
    {
        return [
            'id' => $n->id,
            'category' => $n->category,
            'type' => $n->type,
            'title' => $n->title,
            'body' => $n->body,
            'data' => $n->data ?? (object) [],
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at->toIso8601String(),
            'updated_at' => $n->updated_at->toIso8601String(),
        ];
    }
}
