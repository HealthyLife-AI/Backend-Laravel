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
        $this->call(UsdaFoodSeeder::class);

        // Admin account for the admin panel, created only when both are set
        // in the environment (e.g. on Taqat) — no default credentials are
        // ever shipped. Re-running updates nothing but the role.
        if (filled(env('ADMIN_EMAIL')) && filled(env('ADMIN_PASSWORD'))) {
            $admin = User::firstOrCreate(
                ['email' => env('ADMIN_EMAIL')],
                [
                    'name' => env('ADMIN_NAME', 'HealthyLife Admin'),
                    'password' => Hash::make(env('ADMIN_PASSWORD')),
                    'email_verified_at' => now(),
                ]
            );
            $admin->assignRole('admin');
        }

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
