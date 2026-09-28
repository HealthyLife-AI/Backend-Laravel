<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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

        $this->seedDemoNutritionist();
    }

    /**
     * Demo login for local development and demos. Created only on a local
     * environment or when DEMO_NUTRITIONIST_PASSWORD is set — so a
     * production `db:seed --force` never adds a known account. No
     * password is shipped: it comes from that variable, and a local run
     * without it gets a random one, printed once.
     *
     * `firstOrCreate`, not `User::factory()`: a production `composer
     * install --no-dev` drops `fakerphp/faker`, which the factory needs,
     * and re-running must not hit the unique email. An existing account's
     * password is never changed here.
     */
    private function seedDemoNutritionist(): void
    {
        $password = env('DEMO_NUTRITIONIST_PASSWORD');

        if (! filled($password) && ! app()->environment('local')) {
            return;
        }

        if (! filled($password)) {
            $password = Str::password(16, symbols: false);
        }

        $demo = User::firstOrCreate(
            ['email' => 'nutritionist@example.com'],
            [
                'name' => 'Demo Nutritionist',
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ]
        );
        $demo->assignRole('nutritionist');

        if ($demo->wasRecentlyCreated && ! filled(env('DEMO_NUTRITIONIST_PASSWORD'))) {
            $this->command?->warn("Demo nutritionist created: nutritionist@example.com / {$password}");
        }
    }
}
