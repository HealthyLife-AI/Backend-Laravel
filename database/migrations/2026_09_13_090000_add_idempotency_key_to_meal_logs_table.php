<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * S4-05 / US-07 (mobile offline): make logging safe to retry.
     *
     * A client logs a meal with no signal; the mobile app queues it and
     * replays it on reconnect (S4-12). If the original request actually
     * reached the server and only the response was lost, a plain retry
     * creates a second identical log — silently inflating the denominator
     * of that client's adherence (S4-03) with a meal they ate once.
     *
     * A caller-supplied key rather than a server-side duplicate-window
     * heuristic: a time window cannot distinguish a retry from a client
     * who genuinely ate the same food twice in one sitting (a second
     * portion), and guessing wrong in either direction corrupts the data.
     * The app already knows which queued entry it is replaying.
     *
     * Unique per subscriber, not globally: keys are generated on the
     * device, so two clients' apps could produce the same UUID without it
     * meaning anything. Nullable so a direct API caller that doesn't need
     * retry safety isn't forced to invent one, and so every row that
     * existed before this migration stays valid — MySQL permits repeated
     * NULLs in a unique index.
     */
    public function up(): void
    {
        Schema::table('meal_logs', function (Blueprint $table) {
            $table->uuid('idempotency_key')->nullable()->after('subscriber_id');

            $table->unique(['subscriber_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('meal_logs', function (Blueprint $table) {
            $table->dropUnique(['subscriber_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
