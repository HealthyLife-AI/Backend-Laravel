<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weight_insights', function (Blueprint $table) {
            // The wording rules (config weight_insight.text_version) the text was written under. Rows written before
            // this column are 1, so they are regenerated on their next read under the current rules.
            $table->unsignedSmallInteger('text_version')->default(1)->after('fallback_reason');
        });
    }

    public function down(): void
    {
        Schema::table('weight_insights', function (Blueprint $table) {
            $table->dropColumn('text_version');
        });
    }
};
