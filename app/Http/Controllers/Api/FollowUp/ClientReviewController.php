<?php

namespace App\Http\Controllers\Api\FollowUp;

use App\Http\Controllers\Controller;
use App\Http\Requests\FollowUp\ReviewRequest;
use App\Http\Resources\FollowUpReviewResource;
use App\Models\FollowUpReview;
use App\Models\Subscriber;
use App\Services\FollowUp\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/** The nutritionist's side of follow-up between sessions, for one of their patients. */
class ClientReviewController extends Controller
{
    public function __construct(private readonly ReviewService $reviews) {}

    public function index(Subscriber $subscriber): AnonymousResourceCollection
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        return FollowUpReviewResource::collection($subscriber->followUpReviews()->with('tasks')->get());
    }

    public function store(Subscriber $subscriber, ReviewRequest $request): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        $review = $this->reviews->create($subscriber, $request->user(), $request->validated());

        return (new FollowUpReviewResource($review))->response()->setStatusCode(201);
    }

    public function update(Subscriber $subscriber, string $review, ReviewRequest $request): FollowUpReviewResource
    {
        return new FollowUpReviewResource($this->reviews->update($this->find($subscriber, $review), $request->validated()));
    }

    public function destroy(Subscriber $subscriber, string $review): Response
    {
        $this->find($subscriber, $review)->delete();

        return response()->noContent();
    }

    private function find(Subscriber $subscriber, string $id): FollowUpReview
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        return FollowUpReview::query()->where('subscriber_id', $subscriber->id)->with('tasks')->findOrFail($id);
    }
}
