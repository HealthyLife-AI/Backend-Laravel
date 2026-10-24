<?php

namespace App\Services\FoodPreferences;

use App\Models\PatientFoodPreference;
use App\Models\Subscriber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2, B: what a patient likes and dislikes (a soft preference, never an
 * allergy). Two writers, one table:
 *
 *  - the PATIENT replaces their own rows. The nutritionist's rows are never
 *    removed by it, and a food the nutritionist already classified cannot be
 *    flipped by the patient (a 422 names it).
 *  - the NUTRITIONIST sees and edits the WHOLE list: what she sends is the
 *    list, so anything she leaves out is removed, the patient's rows included.
 *    A row she keeps as it is keeps whoever added it; one she adds is hers; one
 *    whose kind she flips becomes hers (and the patient can no longer flip it back).
 *
 * One row per patient and food (unique), so a food is never in both lists.
 */
class FoodPreferenceService
{
    /**
     * @return array{likes: list<array<string, mixed>>, dislikes: list<array<string, mixed>>}
     */
    public function lists(Subscriber $subscriber): array
    {
        $rows = PatientFoodPreference::query()->where('subscriber_id', $subscriber->id)->with('food:id,name_ar,name_en')->orderBy('id')->get();

        $present = fn (string $kind) => $rows->where('kind', $kind)->map(fn (PatientFoodPreference $p) => [
            'food_id' => $p->food_id,
            // Foods imported from USDA have no Arabic name: fall back to English rather than an empty chip.
            'name_ar' => $p->food?->name_ar ?: $p->food?->name_en,
            'name_en' => $p->food?->name_en,
            'source' => $p->source,
        ])->values()->all();

        return ['likes' => $present('like'), 'dislikes' => $present('dislike')];
    }

    /**
     * @param  list<int>  $likes
     * @param  list<int>  $dislikes
     */
    public function replace(Subscriber $subscriber, array $likes, array $dislikes, string $source): void
    {
        $wanted = [];
        foreach ($likes as $id) {
            $wanted[(int) $id] = 'like';
        }
        foreach ($dislikes as $id) {
            $wanted[(int) $id] = 'dislike';
        }

        DB::transaction(function () use ($subscriber, $wanted, $source) {
            $existing = PatientFoodPreference::query()->where('subscriber_id', $subscriber->id)->get()->keyBy('food_id');
            $errors = [];

            foreach ($wanted as $foodId => $kind) {
                $row = $existing->get($foodId);

                if ($row === null) {
                    PatientFoodPreference::create(['subscriber_id' => $subscriber->id, 'food_id' => $foodId, 'kind' => $kind, 'source' => $source]);

                    continue;
                }

                if ($source === PatientFoodPreference::SOURCE_PATIENT && $row->source === PatientFoodPreference::SOURCE_NUTRITIONIST) {
                    // Hers stands. Asking for the same thing is fine; the opposite is refused.
                    if ($row->kind !== $kind) {
                        $errors[$kind === 'like' ? 'likes' : 'dislikes'] = ["Food {$foodId} was set by your nutritionist and cannot be changed here."];
                    }

                    continue;
                }

                // Already as asked: whoever added it stays recorded as having done so.
                if ($row->kind === $kind) {
                    continue;
                }

                // Mine to change: the patient's own row, or anything when it is the nutritionist writing.
                $row->forceFill(['kind' => $kind, 'source' => $source])->save();
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            // Whatever this writer no longer lists goes. The patient's edit can only
            // remove their own rows; the nutritionist's list is the whole list.
            PatientFoodPreference::query()->where('subscriber_id', $subscriber->id)
                ->when($source === PatientFoodPreference::SOURCE_PATIENT, fn ($q) => $q->where('source', $source))
                ->whereNotIn('food_id', array_keys($wanted) ?: [0])->delete();
        });
    }

    /** Arabic (or English) names of the foods in each list, for the AI draft. @return array{likes: list<string>, dislikes: list<string>} */
    public function namesFor(Subscriber $subscriber): array
    {
        $lists = $this->lists($subscriber);

        return [
            'likes' => array_values(array_filter(array_column($lists['likes'], 'name_ar'))),
            'dislikes' => array_values(array_filter(array_column($lists['dislikes'], 'name_ar'))),
        ];
    }

    /** @return array<int, string> food id => 'like'|'dislike', for the given foods. */
    public function kindsFor(Subscriber $subscriber, array $foodIds): array
    {
        return PatientFoodPreference::query()->where('subscriber_id', $subscriber->id)->whereIn('food_id', $foodIds)->pluck('kind', 'food_id')->all();
    }
}
