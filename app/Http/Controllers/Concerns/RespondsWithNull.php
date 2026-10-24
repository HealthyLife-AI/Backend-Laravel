<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Response;

/**
 * `response()->json(null)` sends `{}` (Laravel turns null into an empty
 * object), which a mobile client cannot tell from a real object. Where the
 * contract says "the thing, or null", this sends the JSON literal `null`.
 */
trait RespondsWithNull
{
    /** @param  array<string, mixed>|null  $payload */
    protected function objectOrNull(?array $payload): Response
    {
        return response(json_encode($payload), 200, ['Content-Type' => 'application/json']);
    }
}
