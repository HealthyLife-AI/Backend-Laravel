<?php

namespace App\Http\Controllers\Api\Wellbeing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wellbeing\SaveWellbeingRequest;
use App\Http\Resources\WellbeingLogResource;
use App\Models\Subscriber;
use App\Services\Wellbeing\WellbeingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * Phase 4, C2: the patient's own daily sleep / stress / mood. No route-bound
 * subscriber: it comes from the token.
 */
class MyWellbeingController extends Controller
{
    public function __construct(private readonly WellbeingService $wellbeing) {}

    /** Entries between `from` and `to` (default: the last 14 days; at most 60 days), oldest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);

        return WellbeingLogResource::collection($this->wellbeing->range($this->subscriber(), $data['from'] ?? null, $data['to'] ?? null));
    }

    /** Create or replace the entry for `{date}`: today or one of the last 7 days. 200 either way. */
    public function upsert(SaveWellbeingRequest $request, string $date): WellbeingLogResource
    {
        $this->validDate($date);

        return new WellbeingLogResource($this->wellbeing->upsert($this->subscriber(), $date, $request->validated()));
    }

    public function destroy(string $date): Response
    {
        $this->validDate($date);

        $log = $this->wellbeing->find($this->subscriber(), $date);
        abort_if($log === null, 404);

        $log->delete();

        return response()->noContent();
    }

    private function validDate(string $date): void
    {
        Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();
    }

    private function subscriber(): Subscriber
    {
        $subscriber = Auth::user()->subscriberProfile;

        abort_if($subscriber === null, 403, 'This account is not set up as a client.');

        return $subscriber;
    }
}
