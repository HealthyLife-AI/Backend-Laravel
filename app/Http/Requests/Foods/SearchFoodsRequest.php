<?php

namespace App\Http\Requests\Foods;

use Illuminate\Foundation\Http\FormRequest;

/** FR-25: search the food database in Arabic and English. */
class SearchFoodsRequest extends FormRequest
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
            'q' => ['required', 'string', 'min:2', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
