<?php

namespace App\Http\Requests\Logs;

use App\Models\MealItem;
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
                ->first();

            if ($mealItem === null) {
                $validator->errors()->add('meal_item_id', 'The selected meal item is not part of your plan.');

                return;
            }

            if ((int) $this->input('food_id') !== (int) $mealItem->food_id) {
                $validator->errors()->add('food_id', 'The food does not match the plan item it is logged against.');
            }
        });
    }
}
