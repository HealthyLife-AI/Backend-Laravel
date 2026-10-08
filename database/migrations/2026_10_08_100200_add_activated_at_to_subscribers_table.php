<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the patient first signed in (pending → active). Null for
     * patients who became active before this column existed: readers fall
     * back to created_at for them, so no backfill is needed.
     */
    public function up(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->timestamp('activated_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->dropColumn('activated_at');
        });
    }
};
