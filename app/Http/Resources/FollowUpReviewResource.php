<?php

namespace App\Http\Resources;

use App\Models\FollowUpReview;
use App\Models\FollowUpTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A follow-up review with its tasks — the same shape for the nutritionist
 * (who sees whether it was acknowledged and which tasks are done) and the
 * patient.
 *
 * @mixin FollowUpReview
 */
class FollowUpReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'note' => $this->note,
            'key_points' => $this->key_points ?? [],
            'tasks' => $this->tasks->map(fn (FollowUpTask $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'done_at' => $task->done_at?->toIso8601String(),
            ])->values(),
            'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
            'edited_at' => $this->edited_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
