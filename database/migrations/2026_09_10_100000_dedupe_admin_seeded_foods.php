<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cleans up the duplicate admin dishes `ArabicFoodSeeder` created before
 * it was made idempotent (see that seeder's comment): its
 * `upsert(uniqueBy: ['name_en'])` had no UNIQUE index behind it, so MySQL
 * ignored the conflict clause and re-inserted the whole 58-dish set on
 * every `db:seed --force`. Production carried two of every dish, which
 * quietly degraded AI drafts as well — `AiDraftPlanService` keeps a plan's
 * headline foods distinct by `food_id`, so two rows for the same dish let
 * "Basmati Rice" be picked as the planned item twice in the same day.
 *
 * Scoped to `source = 'admin'` on purpose: two nutritionist submissions
 * sharing a name are legitimate under BR-5 (a different local recipe with
 * different macros) and must not be merged.
 *
 * The lowest id per name wins, since that's the row existing
 * `meal_items.food_id` values are most likely already pointing at, and any
 * item referencing a duplicate is repointed at it before that duplicate is
 * deleted — `meal_items.food_id` is a restricting foreign key, so deleting
 * a referenced food would otherwise fail outright.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicatedNames = DB::table('foods')
            ->where('source', 'admin')
            ->whereNotNull('name_en')
            ->groupBy('name_en')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('name_en');

        foreach ($duplicatedNames as $name) {
            $ids = DB::table('foods')
                ->where('source', 'admin')
                ->where('name_en', $name)
                ->orderBy('id')
                ->pluck('id');

            $canonicalId = $ids->first();
            $duplicateIds = $ids->slice(1)->all();

            if ($duplicateIds === []) {
                continue;
            }

            DB::table('meal_items')
                ->whereIn('food_id', $duplicateIds)
                ->update(['food_id' => $canonicalId]);

            DB::table('foods')->whereIn('id', $duplicateIds)->delete();
        }
    }

    /**
     * Deliberately empty. This migration deletes rows that should never
     * have existed; re-creating them on rollback would restore the defect,
     * and a plan item repointed at the surviving row can no longer be told
     * apart from one that always pointed there.
     */
    public function down(): void
    {
        //
    }
};
