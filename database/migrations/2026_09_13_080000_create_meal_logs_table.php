<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * S4-01 / FR-17, BR-9: what the client actually ate, as opposed to
     * what was planned for them.
     *
     * `meal_item_id` is the whole point of the table and is deliberately
     * nullable: BR-9 defines a log that references a meal item — the
     * planned item OR one of its permitted alternatives, since both are
     * rows in `meal_items` — as on-plan, and a log with no reference as
     * outside the plan. Adherence (S4-03) is then a count over this one
     * column rather than a re-derivation of what "on-plan" meant, so the
     * rule lives in the data instead of in each query that reads it.
     *
     * It is `nullOnDelete` rather than cascading: if a nutritionist edits
     * a plan and removes an item, the client's history of having eaten
     * something must not disappear with it. The log survives and simply
     * becomes unattributed — which is honest, because the plan it was
     * measured against no longer exists.
     *
     * `quantity_grams` follows `meal_items`, not the SRS §2.4 sketch's
     * `quantity, unit` pair: every macro in this system is stored
     * per-100g, so grams is the only unit any calculation consumes, and a
     * second free-form unit column would have to be converted before it
     * could be compared against the plan it is being measured against.
     */
    public function up(): void
    {
        Schema::create('meal_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('subscribers')->cascadeOnDelete();
            $table->foreignId('food_id')->constrained('foods');
            $table->foreignId('meal_item_id')->nullable()->constrained('meal_items')->nullOnDelete();

            $table->decimal('quantity_grams', 6, 1);
            $table->timestamp('logged_at');

            $table->timestamps();

            // S4-03/S4-04 read this table by client over a date window,
            // and NFR-01 asks for that to stay indexed rather than become
            // a scan once a client has months of logs.
            $table->index(['subscriber_id', 'logged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_logs');
    }
};
