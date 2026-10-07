<?php

namespace App\Http\Controllers\Api\Appointments;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Appointments\AppointmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/** The patient's appointments with their own nutritionist. */
class MyAppointmentController extends Controller
{
    public function __construct(private readonly AppointmentService $appointments) {}

    public function slots(Request $request): JsonResponse
    {
        $type = $request->validate(['type' => ['required', Rule::in(array_keys(Appointment::DURATIONS))]])['type'];
        $nutritionist = User::query()->findOrFail($this->subscriber()->nutritionist_id);

        return response()->json([
            'type' => $type,
            'duration_minutes' => Appointment::DURATIONS[$type],
            'timezone' => config('scheduling.timezone'),
            'video_available' => filled($nutritionist->nutritionistProfile?->meeting_link),
            'days' => collect($this->appointments->slots($nutritionist, $type))->map(fn ($times, $date) => ['date' => $date, 'times' => $times])->values(),
        ]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $scope = $request->validate(['scope' => ['nullable', Rule::in(['upcoming', 'past'])]])['scope'] ?? 'upcoming';

        $query = Appointment::query()->where('subscriber_id', $this->subscriber()->id);
        $scope === 'upcoming'
            ? $query->where('status', 'booked')->where('starts_at', '>', now())->orderBy('starts_at')
            : $query->where(fn ($q) => $q->where('status', '!=', 'booked')->orWhere('starts_at', '<=', now()))->orderByDesc('starts_at');

        return AppointmentResource::collection($query->limit(50)->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(Appointment::DURATIONS))],
            'starts_at' => ['required', 'date'],
            'channel' => ['required', Rule::in(Appointment::CHANNELS)],
            'topics' => ['nullable', 'array', 'max:6'],
            'topics.*' => [Rule::in(Appointment::TOPICS)],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        return (new AppointmentResource($this->appointments->book($this->subscriber(), $data)))->response()->setStatusCode(201);
    }

    public function update(string $appointment, Request $request): AppointmentResource
    {
        $startsAt = $request->validate(['starts_at' => ['required', 'date']])['starts_at'];

        return new AppointmentResource($this->appointments->reschedule($this->mine($appointment), $startsAt, byNutritionist: false));
    }

    public function destroy(string $appointment): AppointmentResource
    {
        return new AppointmentResource($this->appointments->cancel($this->mine($appointment), 'patient'));
    }

    private function mine(string $id): Appointment
    {
        return Appointment::query()->where('subscriber_id', $this->subscriber()->id)->findOrFail($id);
    }

    private function subscriber(): Subscriber
    {
        $subscriber = Auth::user()->subscriberProfile;
        abort_if($subscriber === null, 403, 'This account is not set up as a client.');

        return $subscriber;
    }
}
