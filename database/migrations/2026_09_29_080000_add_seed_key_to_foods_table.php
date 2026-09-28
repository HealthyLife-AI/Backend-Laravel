<?php

use Database\Seeders\ArabicFoodSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stable id for the curated dishes ArabicFoodSeeder ships, so the seeder
 * can recognise a dish after the admin renames or deletes it. Existing
 * dishes are backfilled by their seeded `name_en` among admin rows (the
 * only key the old seeder used); the lowest id wins if a name somehow
 * still appears twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foods', function (Blueprint $table) {
            $table->string('seed_key', 100)->nullable()->unique()->after('usda_fdc_id');
        });

        foreach (ArabicFoodSeeder::DISHES as $dish) {
            $id = DB::table('foods')
                ->where('source', 'admin')
                ->whereNull('seed_key')
                ->where('name_en', $dish['name_en'])
                ->min('id');

            if ($id !== null) {
                DB::table('foods')->where('id', $id)->update(['seed_key' => $dish['seed_key']]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('foods', function (Blueprint $table) {
            $table->dropUnique(['seed_key']);
            $table->dropColumn('seed_key');
        });
    }
};
