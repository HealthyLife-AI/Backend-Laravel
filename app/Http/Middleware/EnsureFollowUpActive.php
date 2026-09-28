<?php

namespace App\Http\Middleware;

use App\Models\Subscriber;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On a nutritionist's write route for one patient (`{subscriber}`),
 * refuses the write while that patient is archived: records stay
 * readable, but no new plans, edits or measurements until follow-up
 * resumes. Registered as `follow-up`.
 *
 * Another nutritionist's patient passes through untouched, so the
 * controller's own `belongsToCaller()` check still answers 404 and this
 * never reveals whether someone else's patient is archived.
 */
class EnsureFollowUpActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $subscriber = $request->route('subscriber');

        if ($subscriber instanceof Subscriber && $subscriber->belongsToCaller() && $subscriber->isArchived()) {
            return response()->json([
                'message' => 'Follow-up with this client has ended. Resume follow-up to make changes.',
                'code' => 'follow_up_ended',
            ], 409);
        }

        return $next($request);
    }
}
