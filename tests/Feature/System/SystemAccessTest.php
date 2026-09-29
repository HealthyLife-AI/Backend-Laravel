<?php

namespace Tests\Feature\System;

use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * The operational diagnostics say which credentials and jobs a deployment
 * has configured. That is the operator's business: admin only. Until now any
 * logged-in account, a patient's included, could read them.
 */
class SystemAccessTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private const ENDPOINTS = ['/api/v1/system/ai-status', '/api/v1/system/scheduler-status', '/api/v1/system/fcm-status'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function patient(): array
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $client = User::factory()->create(['nutritionist_id' => $nutritionist->id]);
        $client->assignRole('client');
        $subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $nutritionist->id, 'user_id' => $client->id]);

        return [$client, $subscriber];
    }

    public function test_an_admin_can_read_all_three(): void
    {
        $admin = tap(User::factory()->create())->assignRole('admin');

        foreach (self::ENDPOINTS as $uri) {
            $this->getJson($uri, $this->bearerFor($admin))->assertOk();
        }
    }

    public function test_a_nutritionist_is_refused(): void
    {
        $token = $this->bearerFor(User::factory()->nutritionist()->create());

        foreach (self::ENDPOINTS as $uri) {
            $this->getJson($uri, $token)->assertForbidden();
        }
    }

    public function test_a_patient_is_refused(): void
    {
        [$client] = $this->patient();
        $token = $this->bearerFor($client);

        foreach (self::ENDPOINTS as $uri) {
            $this->getJson($uri, $token)->assertForbidden();
        }
    }

    public function test_an_archived_patient_still_gets_follow_up_ended(): void
    {
        [$client, $subscriber] = $this->patient();
        $token = $this->bearerFor($client);
        $subscriber->forceFill(['archived_at' => now()])->save();

        foreach (self::ENDPOINTS as $uri) {
            $this->getJson($uri, $token)->assertForbidden()->assertJsonPath('code', 'follow_up_ended');
        }
    }

    public function test_no_token_is_refused(): void
    {
        foreach (self::ENDPOINTS as $uri) {
            $this->getJson($uri)->assertUnauthorized();
        }
    }
}
