<?php

namespace App\Services\Appointments;

use App\Exceptions\ApiCodeException;
use App\Models\Appointment;
use App\Models\NutritionistAvailability;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Booking appointments against the nutritionist's weekly availability.
 *
 * Times are in config('scheduling.timezone'). The grid is 15 minutes: every
 * booked appointment holds its cells in appointment_holds, whose unique
 * index rejects a double booking even under a race (409 slot_taken).
 *
 * The patient books from 12 hours ahead up to 30 days out and may move or
 * cancel until 12 hours before (later: 422 appointment_locked with the
 * nutritionist's WhatsApp). The nutritionist is free of the 12-hour rule
 * but not of availability or overlap. The patient is notified only of
 * changes the nutritionist makes; reminders are local in the app.
 */
class AppointmentService
{
    public const GRID_MINUTES = 15;

    public const LEAD_HOURS = 12;

    public const HORIZON_DAYS = 30;

    public function __construct(private readonly NotificationService $notifications) {}

    private function tz(): string
    {
        return (string) config('scheduling.timezone');
    }

    /**
     * Free start times for an appointment type, grouped by local date.
     *
     * @return array<string, list<string>> 'Y-m-d' => ISO 8601 start times
     */
    public function slots(User $nutritionist, string $type, ?int $ignoreAppointmentId = null, bool $applyLead = true): array
    {
        $duration = Appointment::DURATIONS[$type];
        $now = CarbonImmutable::now($this->tz());
        $earliest = $applyLead ? $now->addHours(self::LEAD_HOURS) : $now;
        $last = $now->startOfDay()->addDays(self::HORIZON_DAYS);

        $windows = NutritionistAvailability::query()->where('nutritionist_id', $nutritionist->id)->get()->groupBy('weekday');
        $daysOff = DB::table('nutritionist_days_off')->where('nutritionist_id', $nutritionist->id)->pluck('date')
            ->map(fn ($d) => substr((string) $d, 0, 10))->flip();
        $held = DB::table('appointment_holds')->where('nutritionist_id', $nutritionist->id)
            ->when($ignoreAppointmentId, fn ($q) => $q->where('appointment_id', '!=', $ignoreAppointmentId))
            ->where('slot_start', '>=', $now->startOfDay()->setTimezone(config('app.timezone')))
            ->pluck('slot_start')->map(fn ($s) => CarbonImmutable::parse($s, config('app.timezone'))->setTimezone($this->tz())->format('Y-m-d H:i'))->flip();

        $result = [];
        for ($day = $now->startOfDay(); $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            $date = $day->toDateString();
            if (isset($daysOff[$date])) {
                continue;
            }
            foreach ($windows->get($day->dayOfWeek, []) as $window) {
                $start = $day->setTimeFromTimeString($window->start_time);
                $end = $day->setTimeFromTimeString($window->end_time);
                for ($t = $start; $t->addMinutes($duration)->lessThanOrEqualTo($end); $t = $t->addMinutes(self::GRID_MINUTES)) {
                    if ($t->lessThan($earliest)) {
                        continue;
                    }
                    $free = true;
                    for ($c = $t; $c->lessThan($t->addMinutes($duration)); $c = $c->addMinutes(self::GRID_MINUTES)) {
                        if (isset($held[$c->format('Y-m-d H:i')])) {
                            $free = false;
                            break;
                        }
                    }
                    if ($free) {
                        $result[$date][] = $t->toIso8601String();
                    }
                }
            }
        }

        foreach ($result as $date => $times) {
            $times = array_values(array_unique($times));
            sort($times);
            $result[$date] = $times;
        }

        return $result;
    }

    /** @param  array{type: string, starts_at: string, channel: string, topics?: list<string>, note?: string|null}  $data */
    public function book(Subscriber $subscriber, array $data): Appointment
    {
        $nutritionist = User::query()->findOrFail($subscriber->nutritionist_id);

        $this->assertNoUpcoming($subscriber);
        $this->assertChannel($nutritionist, $data['channel']);
        $start = $this->assertFreeSlot($nutritionist, $data['type'], $data['starts_at']);

        return $this->withHolds($nutritionist, function () use ($subscriber, $nutritionist, $data, $start) {
            // B6: re-checked under a lock on the patient's row (after the
            // nutritionist's, always in that order): two taps on «احجز» at the
            // same instant can no longer both pass the check above.
            Subscriber::withoutGlobalScopes()->whereKey($subscriber->id)->lockForUpdate()->value('id');
            $this->assertNoUpcoming($subscriber);

            return Appointment::create([
                'subscriber_id' => $subscriber->id,
                'nutritionist_id' => $nutritionist->id,
                'type' => $data['type'],
                'channel' => $data['channel'],
                'starts_at' => $start->setTimezone(config('app.timezone')),
                'ends_at' => $start->addMinutes(Appointment::DURATIONS[$data['type']])->setTimezone(config('app.timezone')),
                'topics' => $data['topics'] ?? [],
                'note' => $data['note'] ?? null,
            ])->refresh();
        });
    }

    public function reschedule(Appointment $appointment, string $startsAt, bool $byNutritionist): Appointment
    {
        $this->assertBooked($appointment);
        if (! $byNutritionist) {
            $this->assertNotLocked($appointment);
        }

        $nutritionist = User::query()->findOrFail($appointment->nutritionist_id);
        $start = $this->assertFreeSlot($nutritionist, $appointment->type, $startsAt, $appointment->id, applyLead: ! $byNutritionist);

        $this->withHolds($nutritionist, function () use ($appointment, $start) {
            DB::table('appointment_holds')->where('appointment_id', $appointment->id)->delete();
            $appointment->forceFill([
                'starts_at' => $start->setTimezone(config('app.timezone')),
                'ends_at' => $start->addMinutes(Appointment::DURATIONS[$appointment->type])->setTimezone(config('app.timezone')),
            ])->save();

            return $appointment;
        });

        if ($byNutritionist) {
            $this->notify($appointment, 'appointment_rescheduled');
        }

        return $appointment;
    }

    public function cancel(Appointment $appointment, string $by, ?string $reason = null): Appointment
    {
        $this->assertBooked($appointment);
        if ($by === 'patient') {
            $this->assertNotLocked($appointment);
        }

        DB::transaction(function () use ($appointment, $by, $reason) {
            DB::table('appointment_holds')->where('appointment_id', $appointment->id)->delete();
            $appointment->forceFill(['status' => 'cancelled', 'cancelled_by' => $by, 'cancel_reason' => $reason])->save();
        });

        if ($by !== 'patient') {
            $this->notify($appointment, 'appointment_cancelled');
        }

        return $appointment;
    }

    public function close(Appointment $appointment, string $status): Appointment
    {
        $this->assertBooked($appointment);
        DB::table('appointment_holds')->where('appointment_id', $appointment->id)->delete();
        $appointment->forceFill(['status' => $status])->save();

        return $appointment;
    }

    /** Ending follow-up: every future appointment is cancelled, the patient told once. */
    public function cancelFutureFor(Subscriber $subscriber): int
    {
        $future = Appointment::query()->where('subscriber_id', $subscriber->id)->where('status', 'booked')->where('starts_at', '>', now())->get();

        foreach ($future as $appointment) {
            DB::table('appointment_holds')->where('appointment_id', $appointment->id)->delete();
            $appointment->forceFill(['status' => 'cancelled', 'cancelled_by' => 'system', 'cancel_reason' => 'follow_up_ended'])->save();
        }

        if ($future->isNotEmpty()) {
            $this->notify($future->first(), 'appointment_cancelled');
        }

        return $future->count();
    }

    // ---- internals --------------------------------------------------------

    private function assertFreeSlot(User $nutritionist, string $type, string $startsAt, ?int $ignore = null, bool $applyLead = true): CarbonImmutable
    {
        $start = CarbonImmutable::parse($startsAt)->setTimezone($this->tz());
        $free = $this->slots($nutritionist, $type, $ignore, $applyLead)[$start->toDateString()] ?? [];

        if (! in_array($start->toIso8601String(), $free, true)) {
            throw new ApiCodeException('This time is not available. Pick one of the free times.', 'slot_unavailable', 422);
        }

        return $start;
    }

    private function assertNoUpcoming(Subscriber $subscriber): void
    {
        if (Appointment::query()->where('subscriber_id', $subscriber->id)->where('status', 'booked')->where('starts_at', '>', now())->exists()) {
            throw new ApiCodeException('You already have an upcoming appointment. Change or cancel it instead.', 'appointment_exists', 409);
        }
    }

    private function assertChannel(User $nutritionist, string $channel): void
    {
        if ($channel === 'video' && blank($nutritionist->nutritionistProfile?->meeting_link)) {
            throw new ApiCodeException('Video calls are not available with this nutritionist yet.', 'video_unavailable', 422);
        }
    }

    private function assertBooked(Appointment $appointment): void
    {
        if ($appointment->status !== 'booked') {
            throw new ApiCodeException('This appointment is no longer booked.', 'appointment_closed', 409);
        }
    }

    private function assertNotLocked(Appointment $appointment): void
    {
        if ($appointment->starts_at->lessThan(now()->addHours(self::LEAD_HOURS))) {
            $whatsapp = User::query()->find($appointment->nutritionist_id)?->nutritionistProfile?->whatsapp_number;

            throw new ApiCodeException(
                'Less than 12 hours to go: contact your nutritionist to change it.',
                'appointment_locked',
                422,
                ['lock_hours' => self::LEAD_HOURS, 'nutritionist_whatsapp' => $whatsapp],
            );
        }
    }

    /** Runs $create in a transaction and holds every 15-minute cell of the result; a taken cell is a 409. */
    private function withHolds(User $nutritionist, callable $create): Appointment
    {
        try {
            return DB::transaction(function () use ($nutritionist, $create) {
                DB::table('users')->where('id', $nutritionist->id)->lockForUpdate()->value('id');
                /** @var Appointment $appointment */
                $appointment = $create();

                $rows = [];
                for ($c = CarbonImmutable::instance($appointment->starts_at); $c->lessThan($appointment->ends_at); $c = $c->addMinutes(self::GRID_MINUTES)) {
                    $rows[] = ['appointment_id' => $appointment->id, 'nutritionist_id' => $nutritionist->id, 'slot_start' => $c->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s')];
                }
                DB::table('appointment_holds')->insert($rows);

                return $appointment;
            });
        } catch (QueryException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique')) {
                throw new ApiCodeException('Someone just took this time. Pick another one.', 'slot_taken', 409);
            }
            throw $e;
        }
    }

    private function notify(Appointment $appointment, string $type): void
    {
        $user = Subscriber::withoutGlobalScopes()->find($appointment->subscriber_id)?->user()->first();

        if ($user !== null) {
            // B5: one key per type. A shared key let a cancel within 5 minutes of a
            // reschedule refresh the «تغيّر موعدك» row and never push the cancellation.
            $this->notifications->notify($user, 'nutritionist', $type, ['appointment_id' => $appointment->id], "appointment:{$appointment->id}:{$type}");
        }
    }
}
