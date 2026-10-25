<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: two notification categories the patient can switch off,
 * `education` (a new post from the nutritionist) and `morning` (the daily
 * "your day is ready" push), both on by default.
 *
 * `patient_notifications.category` was created as an ENUM of the original
 * five. As with `alerts.type` (Phase 1), adding values to an ENUM is a
 * MySQL-only ALTER that SQLite cannot do, so the column is converted once to
 * a plain string: existing rows keep their values, and the set lives in
 * App\Models\PatientNotification. A column that is already a string is left
 * alone.
 *
 * Also: `patient_quick_requests.response_seen_at`, so the morning message
 * can tell an answer the patient has not read yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        $category = collect(Schema::getColumns('patient_notifications'))->firstWhere('name', 'category');

        if ($category !== null && (str_starts_with((string) $category['type'], 'enum') || DB::getDriverName() === 'sqlite')) {
            Schema::table('patient_notifications', function (Blueprint $table) {
                $table->string('category', 20)->change();
            });
        }

        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->boolean('education')->default(true)->after('plan');
            $table->boolean('morning')->default(true)->after('education');
        });

        Schema::table('patient_quick_requests', function (Blueprint $table) {
            $table->timestamp('response_seen_at')->nullable()->after('responded_at');
        });
    }

    public function down(): void
    {
        Schema::table('patient_quick_requests', function (Blueprint $table) {
            $table->dropColumn('response_seen_at');
        });

        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->dropColumn(['education', 'morning']);
        });
    }
};
