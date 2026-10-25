<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weight_insights', function (Blueprint $table) {
            // Why the fixed text was used instead of the model's: not_configured | provider_failed |
            // empty | rejected_length | rejected_digit | rejected_blocklist:<word>. Null when the model's text was used.
            $table->string('fallback_reason', 80)->nullable()->after('is_fallback');
        });
    }

    public function down(): void
    {
        Schema::table('weight_insights', function (Blueprint $table) {
            $table->dropColumn('fallback_reason');
        });
    }
};
