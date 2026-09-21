<?php

namespace App\Http\Requests\MealPlans;

use Illuminate\Foundation\Http\FormRequest;

/**
 * S3-03 follow-up: an optional label for a saved template — see the
 * `meal_plans` migration for why only a template gets one.
 */
class SaveAsTemplateRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
