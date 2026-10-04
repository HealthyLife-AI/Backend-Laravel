<?php

namespace App\Http\Controllers\Api\FollowUp;

use App\Http\Controllers\Controller;
use App\Http\Resources\FollowUpReviewResource;
use App\Models\FollowUpReview;
use App\Models\FollowUpTask;
use App\Models\Subscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

/**
 * The patient's side: read their reviews, acknowledge one («فهمت»), mark a
 * task done or not done. Every write is safe to repeat. Another patient's
 * review or task is a 404.
 */
class MyReviewController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return FollowUpReviewResource::collection($this->subscriber()->followUpReviews()->with('tasks')->get());
    }

    public function acknowledge(string $review): FollowUpReviewResource
    {
        $model = FollowUpReview::query()->where('subscriber_id', $this->subscriber()->id)->with('tasks')->findOrFail($review);

        if ($model->acknowledged_at === null) {
            $model->forceFill(['acknowledged_at' => now()])->save();
        }

        return new FollowUpReviewResource($model);
    }

    public function done(string $task): JsonResponse
    {
        return $this->setDone($task, true);
    }

    public function undone(string $task): JsonResponse
    {
        return $this->setDone($task, false);
    }

    private function setDone(string $id, bool $done): JsonResponse
    {
        $task = FollowUpTask::query()->where('subscriber_id', $this->subscriber()->id)->findOrFail($id);

        if ($done && $task->done_at === null) {
            $task->forceFill(['done_at' => now()])->save();
        } elseif (! $done && $task->done_at !== null) {
            $task->forceFill(['done_at' => null])->save();
        }

        return response()->json(['id' => $task->id, 'title' => $task->title, 'done_at' => $task->done_at?->toIso8601String()]);
    }

    private function subscriber(): Subscriber
    {
        $subscriber = Auth::user()->subscriberProfile;
        abort_if($subscriber === null, 403, 'This account is not set up as a client.');

        return $subscriber;
    }
}
