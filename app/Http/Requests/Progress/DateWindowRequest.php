<?php

namespace App\Http\Requests\Progress;

use Illuminate\Foundation\Http\FormRequest;

/**
 * S4-03/S4-04: the optional date window shared by the adherence and
 * progress endpoints. Both bounds are required together — a half-open
 * range would silently fall back to the default window, which is not what
 * a caller that supplied one bound asked for.
 */
class DateWindowRequest extends FormRequest
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
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
        ];
    }
}
