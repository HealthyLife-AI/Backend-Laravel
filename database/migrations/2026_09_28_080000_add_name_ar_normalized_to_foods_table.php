<?php

use App\Support\ArabicText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Search column for Arabic names, spelling-normalized (see ArabicText):
 * filled by Food's `saving` hook and by the seeders' bulk writes, and
 * backfilled here for rows that already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foods', function (Blueprint $table) {
            $table->string('name_ar_normalized')->nullable()->after('name_ar');
            $table->index('name_ar_normalized');
        });

        DB::table('foods')->whereNotNull('name_ar')->orderBy('id')
            ->select(['id', 'name_ar'])
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('foods')->where('id', $row->id)
                        ->update(['name_ar_normalized' => ArabicText::normalize($row->name_ar)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('foods', function (Blueprint $table) {
            $table->dropIndex(['name_ar_normalized']);
            $table->dropColumn('name_ar_normalized');
        });
    }
};
