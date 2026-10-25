<?php

namespace App\Http\Resources;

use App\Models\NutritionistPost;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase 3, A5: a post as the PATIENT sees it: the text and whether they have
 * opened it, nothing about the audience or who else reads it. `is_read` comes
 * from the feed query (MyPostController).
 *
 * @mixin NutritionistPost
 */
class PatientPostResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'published_at' => $this->published_at?->toIso8601String(),
            'pinned' => $this->pinned,
            'is_read' => (bool) $this->resource->getAttribute('is_read'),
        ];
    }
}
