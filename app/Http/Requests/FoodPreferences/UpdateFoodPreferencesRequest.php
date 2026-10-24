<?php

namespace App\Http\Requests\FoodPreferences;

use App\Models\PatientFoodPreference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Phase 2, B2/B3: the two lists, each replaced as a whole. At most 30 each,
 * approved foods only, and a food cannot be in both.
 */
class UpdateFoodPreferencesRequest extends FormRequest
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
        $approved = Rule::exists('foods', 'id')->where('status', 'approved');
        $max = PatientFoodPreference::MAX_PER_LIST;

        return [
            'likes' => ['present', 'array', "max:{$max}"],
            'likes.*' => ['integer', 'distinct', $approved],
            'dislikes' => ['present', 'array', "max:{$max}"],
            'dislikes.*' => ['integer', 'distinct', $approved],
        ];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [function ($validator) {
            $both = array_intersect((array) $this->input('likes'), (array) $this->input('dislikes'));

            if ($both !== []) {
                $validator->errors()->add('dislikes', 'A food cannot be in both lists.');
            }
        }];
    }
}
