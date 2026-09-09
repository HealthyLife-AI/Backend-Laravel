<?php

namespace App\Http\Requests\Foods;

use Illuminate\Foundation\Http\FormRequest;

/**
 * S3-05 / FR-24, BR-5: a nutritionist submitting a local food for the
 * admin to review. `status`/`source`/`submitted_by` aren't accepted here
 * — the controller sets them (pending, nutritionist, the caller) so a
 * submission can't arrive pre-approved or attributed to someone else.
 */
class SubmitFoodRequest extends FormRequest
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
            // At least one name, same as every other food in this table
            // (Food::scopeSearch matches on either) — not both required.
            'name_en' => ['required_without:name_ar', 'nullable', 'string', 'max:255'],
            'name_ar' => ['required_without:name_en', 'nullable', 'string', 'max:255'],
            'calories_per_100g' => ['required', 'numeric', 'min:0', 'max:9999'],
            'protein_g_per_100g' => ['required', 'numeric', 'min:0', 'max:999'],
            'carbs_g_per_100g' => ['required', 'numeric', 'min:0', 'max:999'],
            'fat_g_per_100g' => ['required', 'numeric', 'min:0', 'max:999'],
            'fiber_g_per_100g' => ['nullable', 'numeric', 'min:0', 'max:999'],
        ];
    }
}
