<?php

namespace App\Http\Requests\Logs;

use App\Models\MealLog;
use App\Services\Logs\LogWindow;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * BR-15: a patient correcting one of their own meal logs. Quantity, time
 * and (for an off-plan log) meal type are editable; the food is not — to
 * change what was eaten, delete the log and log the right food.
 *
 * `authorize()` does the lookup so the failures come in a fixed order:
 * another patient's log (or none) is a 404 before anything else is
 * looked at, then a locked log is a 403 `log_locked`, and only then is
 * the body validated.
 */
class UpdateMealLogRequest extends FormRequest
{
    private ?MealLog $log = null;

    public function authorize(): bool
    {
        $subscriber = Auth::user()?->subscriberProfile;
        abort_if($subscriber === null, 403, 'This account is not set up as a client.');

        $this->log = $subscriber->mealLogs()->findOrFail($this->route('id'));

        app(LogWindow::class)->assertEditable($this->log);

        return true;
    }

    public function mealLog(): MealLog
    {
        return $this->log;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quantity_grams' => ['sometimes', 'required', 'numeric', 'min:1', 'max:5000'],

            // Not in the future here; how far it may move (7 days from where it
            // is, and within the 90-day limit) is checked in the controller.
            'logged_at' => ['sometimes', 'required', 'date', 'before_or_equal:now'],

            'meal_type' => ['sometimes', 'required', Rule::in(MealLog::MEAL_TYPES)],

            // What was eaten can't change, and neither can the plan link
            // or the retry key. Refused rather than silently ignored, so
            // the app learns it is asking for something it can't have.
            'food_id' => ['prohibited'],
            'meal_item_id' => ['prohibited'],
            'idempotency_key' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->hasAny(['quantity_grams', 'logged_at', 'meal_type'])) {
                $validator->errors()->add('quantity_grams', 'Send at least one of quantity_grams, logged_at or meal_type.');

                return;
            }

            // BR-16: an on-plan log's meal comes from the plan.
            if ($this->has('meal_type') && $this->log->log_kind !== MealLog::KIND_OFF_PLAN) {
                $validator->errors()->add('meal_type', 'The meal type of a log that follows the plan is set by the plan and cannot be changed.');
            }
        });
    }
}
