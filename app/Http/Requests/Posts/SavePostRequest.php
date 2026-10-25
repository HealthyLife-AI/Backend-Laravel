<?php

namespace App\Http\Requests\Posts;

use App\Models\NutritionistPost;
use App\Support\ConditionCodes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Phase 3, A3: create (every content field required) or update (any subset).
 * A post is plain text: nothing here renders or strips markup, and nothing
 * downstream interprets it. Patients named by `patient_ids` must be this
 * nutritionist's own.
 */
class SavePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $presence = $this->isMethod('post') ? ['required'] : ['sometimes', 'required'];

        // "other" has no meaning as a target: it is free text per patient.
        $codes = array_values(array_diff(ConditionCodes::CODES, ['other']));

        return [
            'title' => [...$presence, 'string', 'max:'.NutritionistPost::TITLE_MAX],
            'body' => [...$presence, 'string', 'max:'.NutritionistPost::BODY_MAX],
            'audience' => [...$presence, Rule::in(NutritionistPost::AUDIENCES)],
            'condition_codes' => ['required_if:audience,conditions', 'nullable', 'array', 'min:1'],
            'condition_codes.*' => ['string', Rule::in($codes)],
            'patient_ids' => ['required_if:audience,patients', 'nullable', 'array', 'min:1', 'max:500'],
            'patient_ids.*' => ['integer', Rule::exists('subscribers', 'id')->where('nutritionist_id', $this->user()->id)],
            'pinned' => ['sometimes', 'boolean'],
        ];
    }
}
