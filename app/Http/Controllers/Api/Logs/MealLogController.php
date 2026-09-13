<?php

namespace App\Http\Controllers\Api\Logs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logs\IndexMealLogRequest;
use App\Http\Requests\Logs\StoreMealLogRequest;
use App\Http\Resources\MealLogResource;
use App\Models\MealLog;
use App\Services\Adherence\AdherenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

/**
 * S4-01 / FR-17, BR-9, logs.manage.own: the client logging what they
 * actually ate, and reading back their own history.
 *
 * Follows `ClientPlanController` (S3-04): the subscriber is resolved from
 * the authenticated user's JWT, never route-bound, so there is no id here
 * for one client to point at another client's records with. The
 * `logs.manage.own` permission is the role gate; this is the isolation.
 */
class MealLogController extends Controller
{
    private const EAGER_LOAD = ['food'];

    public function __construct(private readonly AdherenceService $adherence) {}

    /**
     * Paginated, and date-filterable via `from`/`to` (Y-m-d). A log
     * history only grows — the mobile progress tab (S4-13) and the
     * dashboard's 7-day comparison (S4-07) both want a window, not an
     * ever-growing full history, and NFR-01 asks for that window to be
     * served by the (subscriber_id, logged_at) index rather than by
     * fetching everything and discarding most of it.
     */
    public function index(IndexMealLogRequest $request): AnonymousResourceCollection
    {
        $subscriber = Auth::user()->subscriberProfile;

        if ($subscriber === null) {
            return MealLogResource::collection(MealLog::query()->whereRaw('1 = 0')->paginate());
        }

        $logs = $subscriber->mealLogs()->with(self::EAGER_LOAD)
            ->when(
                $request->filled('from') && $request->filled('to'),
                fn ($query) => $query->loggedBetween($request->string('from'), $request->string('to')),
            )
            ->paginate();

        return MealLogResource::collection($logs);
    }

    public function store(StoreMealLogRequest $request): JsonResponse
    {
        $subscriber = Auth::user()->subscriberProfile;

        // A user holding logs.manage.own but with no subscriber row is a
        // broken account, not a client with an empty history — 403 rather
        // than inventing a subscriber to attach the log to.
        abort_if($subscriber === null, 403, 'This account is not set up as a client.');

        // S4-05: a replayed offline entry must not become a second log.
        // Returning the EXISTING log with 200 (rather than 201, or an
        // error) is what lets the mobile queue treat a retry as success
        // and stop retrying — an error would keep it queued forever.
        if ($key = $request->string('idempotency_key')->toString()) {
            $existing = $subscriber->mealLogs()->where('idempotency_key', $key)->first();

            if ($existing !== null) {
                return (new MealLogResource($existing->load(self::EAGER_LOAD)))->response();
            }
        }

        $log = new MealLog($request->safe()->except('logged_at'));
        $log->subscriber_id = $subscriber->id;
        $log->logged_at = $request->date('logged_at') ?? now();
        $log->save();

        // Feeds the nutritionist dashboard's "hasn't logged today" count
        // and the client-list adherence filter, both of which have been
        // reading these two columns since Sprint 2 with nothing writing
        // them (see DashboardController). S4-03 sets adherence_status.
        $subscriber->forceFill(['last_logged_at' => $log->logged_at])->save();

        // S4-03: recompute the column the dashboard counts and the client
        // list filters on, now that this client's on-plan ratio has moved.
        $this->adherence->refreshStatus($subscriber);

        return (new MealLogResource($log->load(self::EAGER_LOAD)))->response()->setStatusCode(201);
    }
}
