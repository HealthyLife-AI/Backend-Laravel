<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BR-19: an entry the patient made more than `late_after_days` after the
     * date it is for is accepted and marked late, so the nutritionist can
     * see it came in late (an offline queue that synced a week later, a
     * patient catching up on paper notes). Stored at write time rather than
     * derived on read, so the mark doesn't move if the setting changes.
     *
     * Existing rows are backfilled by the same rule, comparing when the row
     * was created with the date it is for: meal logs by `logged_at`,
     * readings by `recorded_at` — self-reported readings only, since the
     * nutritionist's clinic readings are never late. Done row by row in PHP
     * so the date arithmetic is the same on MySQL and SQLite.
     */
    public function up(): void
    {
        Schema::table('meal_logs', function (Blueprint $table) {
            $table->boolean('is_late')->default(false)->after('logged_at');
        });
        Schema::table('body_composition_readings', function (Blueprint $table) {
            $table->boolean('is_late')->default(false)->after('source');
        });

        $days = (int) config('patient_app.late_after_days', 7);

        DB::table('meal_logs')->select('id', 'logged_at', 'created_at')->orderBy('id')->chunkById(500, function ($rows) use ($days) {
            $late = $rows->filter(fn ($row) => $row->created_at !== null
                && Carbon::parse($row->created_at)->greaterThan(Carbon::parse($row->logged_at)->addDays($days)))->pluck('id');

            if ($late->isNotEmpty()) {
                DB::table('meal_logs')->whereIn('id', $late)->update(['is_late' => true]);
            }
        });

        DB::table('body_composition_readings')->where('source', 'self-reported')->select('id', 'recorded_at', 'created_at')->orderBy('id')
            ->chunkById(500, function ($rows) use ($days) {
                $late = $rows->filter(fn ($row) => $row->created_at !== null
                    && Carbon::parse($row->created_at)->greaterThan(Carbon::parse($row->recorded_at)->endOfDay()->addDays($days)))->pluck('id');

                if ($late->isNotEmpty()) {
                    DB::table('body_composition_readings')->whereIn('id', $late)->update(['is_late' => true]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('meal_logs', function (Blueprint $table) {
            $table->dropColumn('is_late');
        });
        Schema::table('body_composition_readings', function (Blueprint $table) {
            $table->dropColumn('is_late');
        });
    }
};
