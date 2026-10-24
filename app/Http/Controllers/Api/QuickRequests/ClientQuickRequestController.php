<?php

namespace App\Http\Controllers\Api\QuickRequests;

use App\Exceptions\ApiCodeException;
use App\Http\Controllers\Controller;
use App\Http\Resources\QuickRequestResource;
use App\Models\PatientQuickRequest;
use App\Models\Subscriber;
use App\Services\QuickRequests\QuickRequestService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Phase 1, E4: the nutritionist's side. A request carries no nutritionist id
 * of its own, so ownership is reached through its subscriber, the same
 * pattern as alerts: the list goes through `whereHas('subscriber')` (the
 * subscriber's own scope), and a single request is looked up and then
 * checked with belongsToCaller() so another nutritionist's id is a 404.
 */
class ClientQuickRequestController extends Controller
{
    public function __construct(private readonly QuickRequestService $requests) {}

    /** One patient's history, newest first, every status. */
    public function forClient(Subscriber $subscriber): AnonymousResourceCollection
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        return QuickRequestResource::collection($subscriber->quickRequests()->get());
    }

    /** All the nutritionist's patients' requests, optionally by status, newest first. `meta.total` is the count for the sidebar badge. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $request->validate(['status' => ['sometimes', 'in:'.implode(',', PatientQuickRequest::STATUSES)]])['status'] ?? null;

        $requests = PatientQuickRequest::query()
            ->whereHas('subscriber')
            ->with(['subscriber.user'])
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate();

        return QuickRequestResource::collection($requests);
    }

    public function respond(Request $request, string $quickRequest): QuickRequestResource
    {
        $model = $this->own($quickRequest);
        $data = $request->validate(['response' => ['required', 'string', 'max:1000']]);

        return new QuickRequestResource($this->requests->respond($model, $request->user(), $data['response'])->load('subscriber.user'));
    }

    public function close(string $quickRequest): QuickRequestResource
    {
        return new QuickRequestResource($this->requests->close($this->own($quickRequest))->load('subscriber.user'));
    }

    /**
     * The request, only when its patient is the caller's. NOT through the
     * scoped `subscriber` relation, which resolves to null for anyone else:
     * ownership is checked explicitly so it answers 404, and an archived
     * patient (follow-up ended) refuses a write like every other write route.
     */
    private function own(string $id): PatientQuickRequest
    {
        $model = PatientQuickRequest::query()->findOrFail($id);
        $subscriber = Subscriber::withoutGlobalScopes()->findOrFail($model->subscriber_id);

        abort_unless($subscriber->belongsToCaller(), 404);

        if ($subscriber->isArchived()) {
            throw new ApiCodeException('Follow-up with this client has ended. Resume follow-up to make changes.', 'follow_up_ended', 409);
        }

        return $model;
    }
}
