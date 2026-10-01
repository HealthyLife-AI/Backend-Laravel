<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the nutritionist usually replies, as short free text written by
     * them (e.g. «الأحد–الخميس 9–5»), shown on the patient app's "my
     * nutritionist" card. Optional; not parsed.
     */
    public function up(): void
    {
        Schema::table('nutritionist_profiles', function (Blueprint $table) {
            $table->string('reply_hours', 100)->nullable()->after('whatsapp_number');
        });
    }

    public function down(): void
    {
        Schema::table('nutritionist_profiles', function (Blueprint $table) {
            $table->dropColumn('reply_hours');
        });
    }
};
