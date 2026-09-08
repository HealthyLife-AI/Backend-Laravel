<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(ArabicFoodSeeder::class);

        // Local/demo data only. Not `User::factory()->create()`: a
        // production `composer install --no-dev` (Taqat, or any host)
        // drops `fakerphp/faker` (require-dev only), and the factory's
        // `definition()` calls `fake()` — that crashes `db:seed --force`
        // on any environment built without dev deps. `firstOrCreate` also
        // makes this safe to run more than once, which the factory's
        // plain `create()` was not (unique email constraint).
        $demo = User::firstOrCreate(
            ['email' => 'nutritionist@example.com'],
            [
                'name' => 'Demo Nutritionist',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        $demo->assignRole('nutritionist');
    }
}
