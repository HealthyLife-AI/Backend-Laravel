<?php

namespace App\Http\Controllers\Api\Appointments;

use App\Exceptions\ApiCodeException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\NutritionistAvailability;
use App\Services\Appointments\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** The nutritionist's appointments and weekly availability. */
class NutritionistAppointmentController extends Controller
{
    public function __construct(private readonly AppointmentService $appointments) {}

    public function availability(): JsonResponse
    {
        return response()->json($this->presentAvailability());
    }

    public function updateAvailability(Request $request): JsonResponse
    {
        $time = ['required', 'regex:/^([01]\d|2[0-3]):(00|15|30|45)$/'];
        $data = $request->validate([
            'windows' => ['present', 'array', 'max:28'],
            'windows.*.weekday' => ['required', 'integer', 'between:0,6'],
            'windows.*.start' => $time,
            'windows.*.end' => $time,
            'days_off' => ['present', 'array', 'max:120'],
            'days_off.*' => ['date_format:Y-m-d'],
            'meeting_link' => ['nullable', 'url:https', 'max:300'],
        ], [
            'windows.*.start.regex' => 'Times must be on a 15-minute boundary (HH:00, :15, :30 or :45).',
            'windows.*.end.regex' => 'Times must be on a 15-minute boundary (HH:00, :15, :30 or :45).',
        ]);

        foreach ($data['windows'] as $i => $w) {
            if ($w['end'] <= $w['start']) {
                throw new ApiCodeException('A window must end after it starts.', 'window_invalid', 422, [], ["windows.{$i}.end" => ['A window must end after it starts.']]);
            }
        }

        $id = Auth::id();
        DB::transaction(function () use ($data, $id) {
            NutritionistAvailability::query()->where('nutritionist_id', $id)->delete();
            foreach ($data['windows'] as $w) {
                NutritionistAvailability::create(['nutritionist_id' => $id, 'weekday' => $w['weekday'], 'start_time' => $w['start'], 'end_time' => $w['end']]);
            }
            DB::table('nutritionist_days_off')->where('nutritionist_id', $id)->delete();
            DB::table('nutritionist_days_off')->insert(collect($data['days_off'])->unique()->map(fn ($d) => ['nutritionist_id' => $id, 'date' => $d, 'created_at' => now(), 'updated_at' => now()])->values()->all());
            Auth::user()->nutritionistProfile()->firstOrCreate([])->forceFill(['meeting_link' => $data['meeting_link'] ?? null])->save();
        });

        return response()->json($this->presentAvailability());
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $tz = (string) config('scheduling.timezone');
        $from = CarbonImmutable::parse($data['from'] ?? 'today', $tz)->startOfDay();
        $to = CarbonImmutable::parse($data['to'] ?? $from->addDays(13)->toDateString(), $tz)->endOfDay();

        return AppointmentResource::collection(
            Appointment::query()->where('nutritionist_id', Auth::id())
                ->whereBetween('starts_at', [$from->setTimezone(config('app.timezone')), $to->setTimezone(config('app.timezone'))])
                ->with('subscriber.user')->orderBy('starts_at')->get()
        );
    }

    public function update(string $appointment, Request $request): AppointmentResource
    {
        $startsAt = $request->validate(['starts_at' => ['required', 'date']])['starts_at'];

        return new AppointmentResource($this->appointments->reschedule($this->own($appointment), $startsAt, byNutritionist: true)->load('subscriber.user'));
    }

    public function cancel(string $appointment, Request $request): AppointmentResource
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:300']])['reason'] ?? null;

        return new AppointmentResource($this->appointments->cancel($this->own($appointment), 'nutritionist', $reason)->load('subscriber.user'));
    }

    public function complete(string $appointment): AppointmentResource
    {
        return new AppointmentResource($this->appointments->close($this->own($appointment), 'completed')->load('subscriber.user'));
    }

    public function noShow(string $appointment): AppointmentResource
    {
        return new AppointmentResource($this->appointments->close($this->own($appointment), 'no_show')->load('subscriber.user'));
    }

    private function own(string $id): Appointment
    {
        return Appointment::query()->where('nutritionist_id', Auth::id())->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function presentAvailability(): array
    {
        $id = Auth::id();

        return [
            'timezone' => config('scheduling.timezone'),
            'windows' => NutritionistAvailability::query()->where('nutritionist_id', $id)->orderBy('weekday')->orderBy('start_time')->get()
                ->map(fn ($w) => ['weekday' => $w->weekday, 'start' => $w->start_time, 'end' => $w->end_time])->values(),
            'days_off' => DB::table('nutritionist_days_off')->where('nutritionist_id', $id)->orderBy('date')->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->values(),
            'meeting_link' => Auth::user()->nutritionistProfile?->meeting_link,
        ];
    }
}
