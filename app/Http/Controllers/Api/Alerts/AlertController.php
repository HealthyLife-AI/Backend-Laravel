<?php

namespace App\Http\Controllers\Api\Alerts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\IndexAlertRequest;
use App\Http\Resources\AlertResource;
use App\Models\Alert;
use App\Models\Subscriber;
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
            ->latest()
            ->paginate();

        return AlertResource::collection($alerts);
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
