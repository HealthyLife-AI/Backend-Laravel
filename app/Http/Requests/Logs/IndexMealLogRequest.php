<?php

namespace App\Http\Requests\Logs;

use Illuminate\Foundation\Http\FormRequest;

/**
 * S4-01: the optional date window on a client's own log history. Both
 * bounds are required together — a half-open range would silently return
 * the full history, which is the behaviour a caller asking for a window
 * least expects.
 */
class IndexMealLogRequest extends FormRequest
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
