<?php

namespace App\Http\Resources;

use App\Models\NutritionistPost;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3, A: a post as its AUTHOR sees it. (The patient's slimmer shape is
 * built in MyPostController.)
 *
 * @mixin NutritionistPost
 */
class PostResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'audience' => $this->audience,
            'condition_codes' => $this->audience === 'conditions' ? ($this->condition_codes ?? []) : null,
            'patient_ids' => $this->audience === 'patients' ? DB::table('nutritionist_post_targets')->where('post_id', $this->id)->orderBy('subscriber_id')->pluck('subscriber_id') : null,
            'status' => $this->status,
            'pinned' => $this->pinned,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            $this->mergeWhen(isset($this->resource->reads_count), fn () => ['read_count' => (int) $this->resource->reads_count]),
        ];
    }
}
