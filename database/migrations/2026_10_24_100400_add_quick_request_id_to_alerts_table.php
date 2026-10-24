<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            // The request this alert is about, so answering or closing it resolves
            // exactly its own alert. Null for every other alert type, and for any
            // quick_request alert raised before this column existed.
            $table->foreignId('quick_request_id')->nullable()->after('subscriber_id')
                ->constrained('patient_quick_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quick_request_id');
        });
    }
};
