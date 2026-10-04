<?php

namespace App\Http\Controllers\Api\Logs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logs\IndexMealLogRequest;
use App\Http\Requests\Logs\StoreMealLogRequest;
use App\Http\Requests\Logs\UpdateMealLogRequest;
use App\Http\Resources\MealLogResource;
use App\Models\MealLog;
use App\Services\Logs\LogWindow;
use App\Services\Logs\PatientEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

    public function __construct(
        private readonly PatientEntryService $entries,
        private readonly LogWindow $window,
    ) {}

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

        // After the replay check above, so a queued entry that was already
        // saved still gets its 200 however old it is.
        $loggedAt = $request->date('logged_at') ?? now();

        $log = $this->entries->logMeal(
            $subscriber,
            $request->safe()->except(['logged_at', 'meal_type']),
            $loggedAt,
            // Inferred from the time as sent (its own offset).
            $request->mealType($loggedAt),
        );

        return (new MealLogResource($log->load(self::EAGER_LOAD)))->response()->setStatusCode(201);
    }

    /**
     * BR-15: correct one of the patient's own logs — quantity, time, or the
     * meal type of an off-plan one. Never the food.
     */
    public function update(UpdateMealLogRequest $request): MealLogResource
    {
        $log = $request->mealLog();

        DB::transaction(function () use ($request, $log) {
            $log->fill($request->safe()->only(['quantity_grams', 'meal_type']));

            if ($request->filled('logged_at')) {
                $newAt = $request->date('logged_at');
                // BR-15: at most 7 days from where it is now, never past the 90-day limit,
                // so a fresh log can't be walked weeks back one edit at a time.
                $this->window->assertLoggedAtMove($log->logged_at, $newAt);
                $log->logged_at = $newAt->setTimezone(config('app.timezone'));
                // BR-19 follows the new date: late if it arrived over 7 days after it.
                $log->is_late = $this->window->isLateFor($log, $newAt);
            }

            // BR-15: shown to the nutritionist as "edited". Only when
            // something actually changed, so re-sending the same values
            // (a retried request) doesn't mark the log.
            if ($log->isDirty()) {
                $log->edited_at = now();
            }

            $log->save();
            $this->entries->syncSubscriber($log->subscriber);
        });

        return new MealLogResource($log->load(self::EAGER_LOAD));
    }

    /** BR-15: delete one of the patient's own logs while it is still inside the edit window. */
    public function destroy(string $id): Response
    {
        $subscriber = Auth::user()->subscriberProfile;
        abort_if($subscriber === null, 403, 'This account is not set up as a client.');

        $log = $subscriber->mealLogs()->findOrFail($id);
        $this->window->assertEditable($log);

        DB::transaction(function () use ($log, $subscriber) {
            $log->delete();
            $this->entries->syncSubscriber($subscriber);
        });

        return response()->noContent();
    }
}
