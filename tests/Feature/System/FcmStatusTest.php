<?php

namespace Tests\Feature\System;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

class FcmStatusTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_it_reports_configured_via_inline_json_without_revealing_it(): void
    {
        config(['firebase.credentials_path' => null, 'firebase.credentials_json' => '{"client_email":"super-secret@x.iam.gserviceaccount.com"}']);

        $response = $this->getJson('/api/v1/system/fcm-status', $this->bearerFor(User::factory()->nutritionist()->create()));

        $response->assertOk()->assertJson(['configured' => true, 'source' => 'credentials_json']);
        $this->assertStringNotContainsString('super-secret', $response->getContent());
    }

    public function test_it_reports_configured_via_local_file_path(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fcm-status-test');
        file_put_contents($path, '{}');
        config(['firebase.credentials_json' => null, 'firebase.credentials_path' => $path]);

        $this->getJson('/api/v1/system/fcm-status', $this->bearerFor(User::factory()->nutritionist()->create()))
            ->assertOk()
            ->assertJson(['configured' => true, 'source' => 'credentials_path']);

        @unlink($path);
    }

    /** credentials_json wins when both happen to be set — same precedence as FcmPushService itself. */
    public function test_credentials_json_is_reported_when_both_are_set(): void
    {
        config(['firebase.credentials_json' => '{}', 'firebase.credentials_path' => '/some/path.json']);

        $this->getJson('/api/v1/system/fcm-status', $this->bearerFor(User::factory()->nutritionist()->create()))
            ->assertOk()
            ->assertJson(['source' => 'credentials_json']);
    }

    public function test_it_reports_unconfigured(): void
    {
        config(['firebase.credentials_json' => null, 'firebase.credentials_path' => null]);

        $this->getJson('/api/v1/system/fcm-status', $this->bearerFor(User::factory()->nutritionist()->create()))
            ->assertOk()
            ->assertJson(['configured' => false, 'source' => null]);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/system/fcm-status')->assertUnauthorized();
    }
}
