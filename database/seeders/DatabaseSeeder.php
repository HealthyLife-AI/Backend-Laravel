<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(ArabicFoodSeeder::class);

        // Local/demo data only.
        User::factory()->nutritionist()->create([
            'name' => 'Demo Nutritionist',
            'email' => 'nutritionist@example.com',
        ]);
    }
}
