<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * S4-03 rework / FR-18, FR-30, BR-14: classify a client by the
     * DIRECTION of their adherence, not its level.
     *
     * Both nutritionists answered the 70% question and neither treats a
     * level as the trigger. Kholod named "a repeated lapse or a decline"
     * as what actually prompts intervention; Rama cautioned that a
     * percentage alone guarantees no outcome. So the three states change
     * from level judgements (on_track / needs_attention / late) to
     * observed behaviour (stable / declining / stopped_logging).
     *
     * `on_track` and `needs_attention` are NOT mapped across. Each
     * recorded where a client sat relative to 70% at one moment; neither
     * says whether they were rising, steady, or falling, and inventing a
     * direction from a level is precisely the inference the interviews
     * ruled out. Those rows become NULL and are recomputed by
     * `AdherenceService::refreshStatus()` on the client's next log — a
     * brief blank in the dashboard is honest, a fabricated direction is
     * not.
     *
     * `late` IS carried over: it meant "has not logged for N days", which
     * is exactly what `stopped_logging` means. Nothing is inferred.
     */
    public function up(): void
    {
        $this->remap(
            carriedOver: ['late' => 'stopped_logging'],
            newValues: ['stable', 'declining', 'stopped_logging'],
        );
    }

    public function down(): void
    {
        $this->remap(
            carriedOver: ['stopped_logging' => 'late'],
            newValues: ['on_track', 'needs_attention', 'late'],
        );
    }

    /**
     * The old and new value sets share no members, so the column has to
     * be emptied before the constraint can be swapped — otherwise every
     * surviving row violates whichever constraint is not yet in force.
     * The one value that genuinely carries over is re-applied afterwards,
     * by id, once the new constraint is live.
     *
     * `enum()->change()` rather than a raw ALTER: SQLite has no native
     * enum but Laravel emits a CHECK constraint for one, so a MySQL-only
     * ALTER would leave the test database rejecting every new value.
     *
     * @param  array<string, string>  $carriedOver
     * @param  list<string>  $newValues
     */
    private function remap(array $carriedOver, array $newValues): void
    {
        $preserved = [];

        foreach ($carriedOver as $oldValue => $newValue) {
            $preserved[$newValue] = DB::table('subscribers')
                ->where('adherence_status', $oldValue)
                ->pluck('id')
                ->all();
        }

        DB::table('subscribers')->whereNotNull('adherence_status')
            ->update(['adherence_status' => null]);

        Schema::table('subscribers', function (Blueprint $table) use ($newValues) {
            $table->enum('adherence_status', $newValues)->nullable()->change();
        });

        foreach ($preserved as $newValue => $ids) {
            if ($ids !== []) {
                DB::table('subscribers')->whereIn('id', $ids)
                    ->update(['adherence_status' => $newValue]);
            }
        }
    }
};
