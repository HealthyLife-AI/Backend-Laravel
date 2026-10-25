<?php

namespace App\Http\Controllers\Api\QuickRequests;

use App\Http\Controllers\Controller;
use App\Http\Requests\QuickRequests\StoreQuickRequestRequest;
use App\Http\Resources\QuickRequestResource;
use App\Services\QuickRequests\QuickRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 1, E2: the patient's own quick requests. No route-bound id at all
 * (the subscriber comes from the token), and no edit or delete: a request
 * is sent once.
 */
class MyQuickRequestController extends Controller
{
    public function __construct(private readonly QuickRequestService $requests) {}

    public function index(): AnonymousResourceCollection
    {
        $subscriber = Auth::user()->subscriberProfile;

        abort_if($subscriber === null, 403, 'This account is not set up as a client.');

        $requests = $subscriber->quickRequests()->get();

        // Opening the list is reading the answers: the morning message stops mentioning them (Phase 3, B1).
        $subscriber->quickRequests()->where('status', 'answered')->whereNull('response_seen_at')->update(['response_seen_at' => now()]);

        return QuickRequestResource::collection($requests);
    }

    public function store(StoreQuickRequestRequest $request): JsonResponse
    {
        $subscriber = Auth::user()->subscriberProfile;

        abort_if($subscriber === null, 403, 'This account is not set up as a client.');

        return (new QuickRequestResource($this->requests->create($subscriber, $request->validated())))->response()->setStatusCode(201);
    }
}
