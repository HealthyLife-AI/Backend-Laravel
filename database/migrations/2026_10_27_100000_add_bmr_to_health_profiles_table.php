<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_profiles', function (Blueprint $table) {
            // Mifflin-St Jeor BMR (energy at rest), stored beside daily_calorie_needs
            // (TDEE). Null until the profile is next saved or health-profiles:backfill-bmr runs.
            $table->unsignedInteger('bmr')->nullable()->after('daily_calorie_needs');
        });
    }

    public function down(): void
    {
        Schema::table('health_profiles', function (Blueprint $table) {
            $table->dropColumn('bmr');
        });
    }
};
