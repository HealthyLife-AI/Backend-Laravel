<?php

namespace App\Http\Controllers\Api\Clients;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\ListClientsRequest;
use App\Http\Requests\Clients\StoreClientRequest;
use App\Http\Resources\SubscriberResource;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Clients\ClientInviteService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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
    public function __construct(private readonly ClientInviteService $invites) {}

    /**
     * FR-06: filterable, searchable client list. `search` matches name
     * (on the linked user) or client code via a prefix index — see
     * Food::scopeSearch for why prefix, not FULLTEXT/LIKE '%...%'
     * (LIKE '%...%' can't use an index at all; this can).
     */
    public function index(ListClientsRequest $request): AnonymousResourceCollection
    {
        $query = Subscriber::query()->with('user');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
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
     * FR-02: create the client's auth row (name + phone only — no email,
     * no usable password until activation) and its domain profile, then
     * issue a single-use invite (FR-03/BR-3). Wrapped in a transaction so
     * a failure partway never leaves an orphaned user with no subscriber
     * profile (NFR-04).
     */
    public function store(StoreClientRequest $request): JsonResponse
    {
        $nutritionist = $request->user();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return DB::transaction(function () use ($request, $nutritionist) {
                    $user = User::create([
                        'name' => $request->string('name'),
                        'phone' => $request->string('phone'),
                        // Unusable until the client sets a real one via
                        // their invite link (ActivateInviteController).
                        'password' => Hash::make(Str::random(40)),
                        'nutritionist_id' => $nutritionist->id,
                    ]);
                    $user->assignRole('client');

                    $subscriber = Subscriber::create([
                        'user_id' => $user->id,
                        'code' => $this->nextClientCode($nutritionist),
                        'goal' => $request->string('goal'),
                        // Explicit, even though the migration defaults to
                        // 'pending' at the DB level: Eloquent doesn't know
                        // about schema-level defaults on a freshly built
                        // model, so the in-memory `status` would read null
                        // until a `fresh()`/`refresh()` — the very next
                        // line serializes this same instance into the
                        // response, so it must already be correct.
                        'status' => 'pending',
                    ]);

                    $invite = $this->invites->issue($subscriber);

                    return response()->json([
                        'client' => new SubscriberResource($subscriber->load('user')),
                        'invite_token' => $invite['plain'],
                        'invite_expires_at' => $invite['model']->expires_at->toIso8601String(),
                    ], 201);
                });
            } catch (QueryException $e) {
                // Unique-code collision from a concurrent add for the same
                // nutritionist — retry with a freshly computed code rather
                // than surface a 500 for what's just a race, not an error.
                if ($attempt === 3 || ! str_contains($e->getMessage(), 'subscribers_nutritionist_id_code_unique')) {
                    throw $e;
                }
            }
        }

        abort(500, 'Could not allocate a client code.');
    }

    public function show(Subscriber $subscriber): SubscriberResource
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        return new SubscriberResource($subscriber->load('user'));
    }

    private function nextClientCode(User $nutritionist): string
    {
        $sequence = $nutritionist->subscribers()->count() + 101;

        return sprintf('PT-%03d', $sequence);
    }
}
