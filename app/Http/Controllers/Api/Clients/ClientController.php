<?php

namespace App\Http\Controllers\Api\Clients;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\ListClientsRequest;
use App\Http\Requests\Clients\StoreClientRequest;
use App\Http\Resources\SubscriberResource;
use App\Models\PatientGoal;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Clients\ClientCodeAllocator;
use App\Services\Clients\ClientDeletionService;
use App\Support\TemporaryPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * F-2 / US-02: add a client, list/filter/search a nutritionist's own
 * clients. Every query here is implicitly scoped to the authenticated
 * nutritionist by Subscriber's `BelongsToNutritionist` trait (BR-2 /
 * NFR-12) — no controller code filters by nutritionist_id explicitly,
 * because forgetting to is exactly the class of bug that trait exists to
 * make impossible.
 */
class ClientController extends Controller
{
    public function __construct(
        private readonly ClientCodeAllocator $codes,
        private readonly ClientDeletionService $deletion,
    ) {}

    /**
     * FR-06: filterable, searchable client list. `search` matches name
     * (on the linked user) or client code via a prefix index — see
     * Food::scopeSearch for why prefix, not FULLTEXT/LIKE '%...%'
     * (LIKE '%...%' can't use an index at all; this can).
     */
    public function index(ListClientsRequest $request): AnonymousResourceCollection
    {
        $query = Subscriber::query()->with(['user', 'patientGoal'])->withCount(['proposals as pending_proposals_count' => fn ($q) => $q->where('status', 'pending')]);

        // Archived patients are a separate list, never mixed into the roster.
        if ($request->boolean('archived')) {
            $query->archived();
        } else {
            $query->inFollowUp();
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->boolean('pending_proposals')) {
            $query->whereHas('proposals', fn ($q) => $q->where('status', 'pending'));
        }

        if ($request->filled('adherence')) {
            $query->where('adherence_status', $request->string('adherence'));
        }

        if ($request->filled('search')) {
            $term = $request->string('search');
            $query->where(function ($q) use ($term) {
                $q->where('code', 'like', "{$term}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "{$term}%"));
            });
        }

        $clients = $query->latest()->paginate($request->integer('per_page', 20));

        return SubscriberResource::collection($clients);
    }

    /**
     * FR-02: create the client's auth row (name, phone, username — no
     * email) with a generated password, and its domain profile. The
     * username and the plain password are returned ONCE, in this response,
     * for the nutritionist to send on WhatsApp; only the hash is stored.
     * No invite link any more (Part A). Wrapped in a transaction so a
     * failure partway never leaves an orphaned user with no subscriber
     * profile (NFR-04).
     */
    public function store(StoreClientRequest $request): JsonResponse
    {
        $nutritionist = $request->user();

        // Three attempts only so a database deadlock (two patients added at
        // the same instant) is retried instead of surfacing as a 500. The
        // patient code can no longer collide: ClientCodeAllocator hands out
        // numbers under a row lock and never reuses one.
        $password = TemporaryPassword::generate();

        return DB::transaction(function () use ($request, $nutritionist, $password) {
            $user = User::create([
                'name' => $request->string('name')->toString(),
                'phone' => $request->string('phone')->toString(),
                'username' => $request->string('username')->toString(),
                // Hashed by the model's cast; the plain text only goes back in this response.
                'password' => $password,
                'nutritionist_id' => $nutritionist->id,
            ]);
            $user->forceFill(['password_is_temporary' => true])->save();
            $user->assignRole('client');

            $subscriber = Subscriber::create([
                'user_id' => $user->id,
                'code' => $this->codes->next($nutritionist),
                // Legacy column from the structured goal (B10). A plain string:
                // the Stringable from $request->string() made the goal_type
                // below always 'other' (B12).
                'goal' => PatientGoal::LEGACY_GOAL[$request->string('goal')->toString()],
                // Explicit, even though the migration defaults to
                // 'pending' at the DB level: Eloquent doesn't know
                // about schema-level defaults on a freshly built
                // model, so the in-memory `status` would read null
                // until a `fresh()`/`refresh()` — the very next
                // line serializes this same instance into the
                // response, so it must already be correct.
                'status' => 'pending',
            ]);

            // The structured goal starts from the one chosen when adding the patient.
            PatientGoal::create([
                'subscriber_id' => $subscriber->id,
                'goal_type' => $request->string('goal')->toString(),
            ]);

            return response()->json([
                'client' => new SubscriberResource($subscriber->load(['user', 'patientGoal'])),
                'credentials' => ['username' => $user->username, 'password' => $password],
            ], 201);
        }, 3);
    }

    public function show(Subscriber $subscriber): SubscriberResource
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        return (new SubscriberResource($subscriber->load(['user', 'patientGoal'])))->withConsent();
    }

    /**
     * Removes the client entirely: their login account, and through the
     * `subscribers.user_id` cascade everything recorded about them —
     * health profile, readings, plans, logs, alerts, summaries, invites.
     * Irreversible by design (the dashboard confirms first); another
     * nutritionist's client is a 404, same as every other client route.
     */
    public function destroy(Subscriber $subscriber): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        $this->deletion->delete($subscriber);

        return response()->json(null, 204);
    }
}
