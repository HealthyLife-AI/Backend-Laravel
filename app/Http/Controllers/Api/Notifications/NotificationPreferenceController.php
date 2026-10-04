<?php

namespace App\Http\Controllers\Api\Notifications;

use App\Exceptions\ApiCodeException;
use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * The patient's notification settings. Created with defaults the first time
 * they are read. The app sends the device's IANA timezone; quiet hours are
 * read in it (else in config('scheduling.timezone')).
 */
class NotificationPreferenceController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json($this->present(NotificationPreference::for(Auth::user())));
    }

    public function update(Request $request): JsonResponse
    {
        $time = ['string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'];

        $data = $request->validate([
            'locale' => ['sometimes', Rule::in(NotificationPreference::LOCALES)],
            'meals' => ['sometimes', 'boolean'],
            'measurements' => ['sometimes', 'boolean'],
            'nutritionist' => ['sometimes', 'boolean'],
            'plan' => ['sometimes', 'boolean'],
            'quiet_hours_enabled' => ['sometimes', 'boolean'],
            'quiet_start' => ['sometimes', ...$time],
            'quiet_end' => ['sometimes', ...$time],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        if (filled($data['timezone'] ?? null) && ! in_array($data['timezone'], timezone_identifiers_list(), true)) {
            throw new ApiCodeException(
                'The timezone is not a valid IANA timezone.',
                'invalid_timezone',
                422,
                [],
                ['timezone' => ['The timezone must be an IANA name such as Asia/Gaza.']],
            );
        }

        $prefs = NotificationPreference::for(Auth::user());
        $prefs->fill($data)->save();

        return response()->json($this->present($prefs->fresh()));
    }

    /** @return array<string, mixed> */
    private function present(NotificationPreference $p): array
    {
        return [
            'locale' => $p->locale,
            'categories' => [
                'meals' => $p->meals,
                'measurements' => $p->measurements,
                'nutritionist' => $p->nutritionist,
                'plan' => $p->plan,
                'system' => true,
            ],
            'quiet_hours' => ['enabled' => $p->quiet_hours_enabled, 'start' => $p->quiet_start, 'end' => $p->quiet_end],
            'timezone' => $p->timezone,
            'effective_timezone' => $p->effectiveTimezone(),
        ];
    }
}
