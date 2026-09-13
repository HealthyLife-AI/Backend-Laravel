<?php

namespace App\Http\Requests\Nutritionists;

use Illuminate\Foundation\Http\FormRequest;

/**
 * S4-00: the nutritionist editing their own professional details.
 *
 * `plan_tier` is deliberately NOT accepted. It is billing state — see
 * NutritionistProfile's docblock — and accepting it here would let a
 * nutritionist move themselves onto a paid tier for free by adding one
 * field to the request body.
 */
class UpdateNutritionistProfileRequest extends FormRequest
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
            'specialty' => ['nullable', 'string', 'max:255'],
            'clinic_name' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
