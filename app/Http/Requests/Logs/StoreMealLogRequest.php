<?php

namespace App\Http\Requests\Logs;

use App\Models\MealItem;
use App\Services\Logs\PatientEntryService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * S4-01 / FR-17, BR-9: a client logging one thing they actually ate.
 *
 * `subscriber_id` is never accepted from the caller — the controller
 * resolves it from the authenticated user — so a client cannot write a
 * log onto someone else's record.
 */
class StoreMealLogRequest extends FormRequest
{
    /** The plan item the log is against, once it has passed the ownership check. */
    private ?MealItem $planItem = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // What was actually eaten. Required even when meal_item_id is
            // present: the log has to stand on its own once a plan is
            // edited and meal_item_id is nulled out (see the migration).
            'food_id' => ['required', 'integer', Rule::exists('foods', 'id')->where('status', 'approved')],

            // BR-9: present = on-plan (a planned item or one of its
            // alternatives), absent = eaten outside the plan. Ownership
            // is checked in withValidator(), not here — an `exists` rule
            // would confirm the row exists globally, which is exactly the
            // check that would let one client reference another's item.
            'meal_item_id' => ['nullable', 'integer'],

            // BR-16: which meal this was. Optional: for an on-plan log the
            // server takes the plan meal, and for an off-plan one without a
            // recognisable value it infers the meal from the time of day
            // (mealType()). Never refused, so an entry queued offline by an
            // app that didn't send one, or sent "Lunch", is still saved.
            'meal_type' => ['nullable'],

            'quantity_grams' => ['required', 'numeric', 'min:1', 'max:5000'],

            // S4-05: supplied by the mobile app so a replayed offline
            // entry is recognised as the same log rather than a second
            // meal. Optional — a caller with no retry queue needn't
            // invent one.
            'idempotency_key' => ['nullable', 'uuid'],

            // Optional so the mobile app can send the real time an entry
            // was made while offline, rather than the time it synced.
            'logged_at' => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }

    /**
     * BR-16: the meal the log belongs to — the plan meal when on-plan, else
     * the meal type sent (case-insensitive), else the meal whose time range
     * holds `$at` in its own timezone (see MealTypeInference).
     */
    public function mealType(CarbonInterface $at): string
    {
        return app(PatientEntryService::class)->mealType($this->planItem, $this->input('meal_type'), $at);
    }

    /**
     * The two checks that can't be expressed as a plain rule, because
     * both depend on who is asking:
     *
     *  1. A referenced `meal_item` must belong to a plan assigned to THIS
     *     client. Without it, any client could pass any meal item id and
     *     have it counted as on-plan against someone else's plan.
     *  2. `food_id` must match that item's own food. "I ate the planned
     *     item, but the food was something else" is not a coherent claim,
     *     and letting it through would make adherence (S4-03) count a
     *     meal as on-plan that never matched the plan.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $mealItemId = $this->input('meal_item_id');

            if ($mealItemId === null) {
                return;
            }

            $subscriber = Auth::user()?->subscriberProfile;

            $mealItem = MealItem::query()
                ->whereKey($mealItemId)
                ->whereHas('meal.mealPlan', fn ($query) => $query->where('subscriber_id', $subscriber?->id))
                ->with('meal')
                ->first();

            if ($mealItem === null) {
                $validator->errors()->add('meal_item_id', 'The selected meal item is not part of your plan.');

                return;
            }

            if ((int) $this->input('food_id') !== (int) $mealItem->food_id) {
                $validator->errors()->add('food_id', 'The food does not match the plan item it is logged against.');

                return;
            }

            $this->planItem = $mealItem;
        });
    }
}
