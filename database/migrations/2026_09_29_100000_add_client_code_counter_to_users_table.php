<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A per-nutritionist counter for patient codes (`PT-101`, `PT-102`, …)
     * that only ever goes up.
     *
     * Codes used to be `count(patients) + 101`, so any delete made the next
     * patient collide with an existing code (a 500 after three retries) and
     * could hand a deleted patient's code to someone new. `client_code_counter`
     * holds the last number issued; ClientCodeAllocator increments it under a
     * row lock, so a number is never issued twice, even after the
     * highest-numbered patient is deleted.
     *
     * Existing nutritionists start at the highest number already in use, so
     * the next code follows their current roster. Only meaningful on
     * nutritionist rows; every other user carries the unused default.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('client_code_counter')->default(100)->after('nutritionist_id');
        });

        $highest = [];

        DB::table('subscribers')->select('id', 'nutritionist_id', 'code')->orderBy('id')->chunkById(500, function ($rows) use (&$highest) {
            foreach ($rows as $row) {
                if (preg_match('/^PT-(\d+)$/', (string) $row->code, $m)) {
                    $highest[$row->nutritionist_id] = max($highest[$row->nutritionist_id] ?? 0, (int) $m[1]);
                }
            }
        });

        foreach ($highest as $nutritionistId => $number) {
            DB::table('users')
                ->where('id', $nutritionistId)
                ->where('client_code_counter', '<', $number)
                ->update(['client_code_counter' => $number]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('client_code_counter');
        });
    }
};
