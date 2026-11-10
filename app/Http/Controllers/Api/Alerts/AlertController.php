<?php

namespace App\Http\Controllers\Api\Alerts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\IndexAlertRequest;
use App\Http\Requests\Alerts\ReadAllAlertsRequest;
use App\Http\Resources\AlertResource;
use App\Models\Alert;
use App\Models\Subscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * S5-02 / FR-20, alerts.view (nutritionist-only per the permission
 * matrix): a nutritionist's alerts across their whole roster.
 *
 * `Alert` carries no `nutritionist_id` of its own, so isolation is
 * reached through `whereHas('subscriber')` — that subquery honours
 * `Subscriber`'s own `NutritionistScope`, the same pattern this codebase
 * already uses wherever a child table has no scope of its own to apply.
 */
class AlertController extends Controller
{
    public function index(IndexAlertRequest $request): AnonymousResourceCollection
    {
        $alerts = Alert::query()
            ->whereHas('subscriber')
            // For AlertResource's subscriber_name/subscriber_code — one
            // join per page, not a query per row.
            ->with(['subscriber.user'])
            // `boolean()` coerces an ABSENT key to false too, so presence
            // must be checked with `has()` — using boolean()'s own null
            // default here would silently filter to is_read=false on
            // every unfiltered request instead of returning everything.
            ->when($request->has('is_read'), fn ($query) => $query->where('is_read', $request->boolean('is_read')))
            ->when($request->filled('subscriber_id'), fn ($query) => $query->where('subscriber_id', $request->integer('subscriber_id')))
            ->when($request->filled('severity'), fn ($query) => $query->where('severity', $request->string('severity')->toString()))
            // Phase 1, C3: important first, then newest. A string compare, not a
            // CASE: 'important' sorts before 'normal' on both MySQL and SQLite.
            ->orderBy('severity')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate();

        return AlertResource::collection($alerts);
    }

    /**
     * Marks the caller's unread alerts read, narrowed by the same optional
     * filters as index() so "mark all" means "all of what I'm looking at".
     *
     * One UPDATE … WHERE EXISTS (subscriber …): the query is built exactly
     * like index(), and whereHas('subscriber') is what keeps it to the
     * caller's own roster (Subscriber's NutritionistScope applies inside
     * the subquery). A subscriber_id from another roster passes validation
     * (it exists) but matches nothing here, so it updates 0 rows.
     */
    public function readAll(ReadAllAlertsRequest $request): JsonResponse
    {
        $updated = Alert::query()
            ->whereHas('subscriber')
            ->where('is_read', false)
            ->when($request->filled('subscriber_id'), fn ($query) => $query->where('subscriber_id', $request->integer('subscriber_id')))
            ->when($request->filled('severity'), fn ($query) => $query->where('severity', $request->string('severity')->toString()))
            ->update(['is_read' => true]);

        return response()->json(['updated' => $updated]);
    }

    public function markRead(Alert $alert): AlertResource
    {
        // NOT $alert->subscriber: `belongsTo` honours Subscriber's own
        // NutritionistScope, so that relation resolves to null the moment
        // the caller isn't the owning nutritionist — belongsToCaller() on
        // null is a fatal error, not the 404 this needs to return. The
        // scope is bypassed here deliberately, precisely so ownership can
        // be checked explicitly instead of the scope silently hiding it.
        $subscriber = Subscriber::withoutGlobalScopes()->findOrFail($alert->subscriber_id);

        abort_unless($subscriber->belongsToCaller(), 404);

        $alert->update(['is_read' => true]);

        return new AlertResource($alert);
    }
}
