<?php

namespace App\Http\Requests\FollowUp;

use App\Models\FollowUpReview;
use App\Models\FollowUpTask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create or edit a follow-up review (the whole review is sent each time). */
class ReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rating' => ['nullable', Rule::in(FollowUpReview::RATINGS)],
            'note' => ['nullable', 'string', 'max:'.FollowUpReview::MAX_NOTE_LENGTH],
            'key_points' => ['sometimes', 'array', 'max:'.FollowUpReview::MAX_KEY_POINTS],
            'key_points.*' => ['nullable', 'string', 'max:300'],
            'tasks' => ['sometimes', 'array', 'max:'.FollowUpReview::MAX_TASKS],
            'tasks.*.id' => ['nullable', 'integer'],
            'tasks.*.title' => ['required', 'string', 'max:'.FollowUpTask::MAX_TITLE_LENGTH],
        ];
    }
}
