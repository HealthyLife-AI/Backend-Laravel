<?php

namespace Tests\Feature\Auth;

use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/** A2: password_is_temporary is on the patient's user object and clears when they choose their own. */
class TemporaryPasswordFlagTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    public function test_the_flag_is_exposed_and_flips_after_put_me_password(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $nutritionist = User::factory()->nutritionist()->create();
        $patient = Subscriber::factory()->active()->forNutritionist($nutritionist)->create();
        $patient->user->forceFill(['password' => Hash::make('TempPass7k'), 'password_is_temporary' => true])->save();

        $this->getJson('/api/v1/auth/me', $this->bearerFor($patient->user))->assertOk()->assertJsonPath('password_is_temporary', true);

        $fresh = $this->putJson('/api/v1/me/password', [
            'current_password' => 'TempPass7k',
            'password' => 'MyOwnPass9',
            'password_confirmation' => 'MyOwnPass9',
        ], $this->bearerFor($patient->user))->assertOk()->assertJsonPath('user.password_is_temporary', false)->json();

        $this->assertFalse($patient->user->fresh()->password_is_temporary);
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$fresh['access_token']])->assertJsonPath('password_is_temporary', false);
    }

    public function test_nutritionists_do_not_carry_the_flag(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $nutritionist = User::factory()->nutritionist()->create();

        $this->getJson('/api/v1/auth/me', $this->bearerFor($nutritionist))->assertOk()->assertJsonMissingPath('password_is_temporary');
    }
}
