<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_logout_revokes_the_refresh_token_so_it_cannot_be_reused(): void
    {
        $user = User::factory()->nutritionist()->create();
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json();

        $this->postJson('/api/v1/auth/logout', [
            'refresh_token' => $login['refresh_token'],
        ])->assertOk();

        $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $login['refresh_token'],
        ])->assertUnauthorized();
    }
}
