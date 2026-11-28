<?php

namespace Tests\Feature\Audit\Accounts;

use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * Audit I7: ending a session must also stop pushes to the old device. `users.fcm_token` is only ever
 * changed by PUT /me/fcm-token; password change, patient credential reset, archive and logout leave it.
 */
class SessionRevocationTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    private Subscriber $subscriber;

    private User $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->subscriber = Subscriber::factory()->forNutritionist($this->nutritionist)->create(['status' => 'active', 'activated_at' => now()]);
        $this->patient = $this->subscriber->user;
        $this->patient->forceFill(['fcm_token' => 'old-device-token', 'username' => 'pt'.uniqid(), 'password' => Hash::make('Old-Password-123')])->save();
    }

    public function test_nutritionist_credential_reset_stops_pushes_to_the_old_device(): void
    {
        $this->postJson("/api/v1/clients/{$this->subscriber->id}/reset-password", [], $this->bearerFor($this->nutritionist))->assertSuccessful();

        $this->assertNull($this->patient->fresh()->fcm_token, 'after a credential reset (e.g. lost phone) the old device still receives health pushes');
    }

    public function test_password_change_stops_pushes_to_other_devices(): void
    {
        $this->putJson('/api/v1/me/password', ['current_password' => 'Old-Password-123', 'password' => 'New-Password-456!', 'password_confirmation' => 'New-Password-456!'], $this->bearerFor($this->patient))->assertSuccessful();

        $this->assertNull($this->patient->fresh()->fcm_token, 'password change ends sessions but keeps pushing to the old device');
    }

    public function test_archive_stops_pushes(): void
    {
        $this->postJson("/api/v1/clients/{$this->subscriber->id}/archive", [], $this->bearerFor($this->nutritionist))->assertSuccessful();

        $this->assertNull($this->patient->fresh()->fcm_token, 'archived patient keeps an FCM token');
    }

    /** Rate limits key on $request->ip(); without TRUSTED_PROXIES every user is the proxy's IP. */
    public function test_forgot_password_bucket_is_shared_by_all_users_of_one_ip(): void
    {
        $codes = [];
        for ($i = 0; $i < 6; $i++) {
            $codes[] = $this->postJson('/api/v1/auth/forgot-password', ['email' => "user{$i}@example.test"], ['REMOTE_ADDR' => '10.0.3.17'])->status();
        }
        $this->assertNotSame(429, end($codes), 'six different people behind one proxy IP: the 6th is throttled ('.implode(',', $codes).')');
    }
}
