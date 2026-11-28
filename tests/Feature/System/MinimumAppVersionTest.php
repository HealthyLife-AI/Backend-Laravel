<?php

namespace Tests\Feature\System;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit MO-06: a minimum supported app version the server can raise. An app
 * older than it gets 426 `app_update_required` on the patient routes (me/*,
 * auth/*), even before it is signed in, and shows a blocking update screen.
 * A request WITHOUT the version header passes, so builds that never sent it
 * (and the dashboard) keep working until the minimum is raised on purpose.
 */
class MinimumAppVersionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app_versions.min_supported' => ['android' => '1.4.0', 'ios' => '1.6.0'],
            'app_versions.store_url' => ['android' => 'https://play.example.test/app', 'ios' => null],
        ]);
    }

    /** @return array<string, string> */
    private function app(string $version, string $platform = 'android'): array
    {
        return ['X-App-Version' => $version, 'X-App-Platform' => $platform];
    }

    public function test_an_app_below_the_minimum_gets_426_with_the_store_link(): void
    {
        $this->getJson('/api/v1/me/today', $this->app('1.3.9'))
            ->assertStatus(426)
            ->assertJsonPath('code', 'app_update_required')
            ->assertJsonPath('store_url', 'https://play.example.test/app')
            ->assertJsonPath('min_supported', '1.4.0')
            ->assertJsonStructure(['message']);
    }

    public function test_it_is_refused_before_sign_in_so_a_signed_out_app_still_learns_it_must_update(): void
    {
        $this->postJson('/api/v1/auth/login', ['username' => 'x', 'password' => 'y'], $this->app('1.0.0'))
            ->assertStatus(426)
            ->assertJsonPath('code', 'app_update_required');
    }

    public function test_the_minimum_itself_and_newer_builds_pass(): void
    {
        $this->getJson('/api/v1/me/today', $this->app('1.4.0'))->assertStatus(401);
        $this->getJson('/api/v1/me/today', $this->app('1.10.2+57'))->assertStatus(401);
    }

    public function test_a_request_without_the_header_passes_so_installed_builds_keep_working(): void
    {
        $this->getJson('/api/v1/me/today')->assertStatus(401);
    }

    public function test_a_malformed_version_is_not_blocked(): void
    {
        $this->getJson('/api/v1/me/today', $this->app('banana'))->assertStatus(401);
    }

    public function test_the_platform_picks_the_minimum(): void
    {
        $this->getJson('/api/v1/me/today', $this->app('1.5.0', 'android'))->assertStatus(401);
        $this->getJson('/api/v1/me/today', $this->app('1.5.0', 'ios'))->assertStatus(426)->assertJsonPath('min_supported', '1.6.0');
    }

    public function test_only_the_patient_routes_are_checked(): void
    {
        // The dashboard's routes: an old version header is ignored there.
        $this->getJson('/api/v1/clients', $this->app('0.0.1'))->assertStatus(401);
    }

    public function test_the_message_follows_accept_language(): void
    {
        $ar = $this->getJson('/api/v1/me/today', $this->app('1.0.0') + ['Accept-Language' => 'ar'])->json('message');
        $en = $this->getJson('/api/v1/me/today', $this->app('1.0.0') + ['Accept-Language' => 'en-US'])->json('message');

        $this->assertNotSame($ar, $en);
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $ar);
    }

    public function test_the_default_minimum_blocks_nobody(): void
    {
        config(['app_versions.min_supported' => ['android' => '0.0.0', 'ios' => '0.0.0']]);

        $this->getJson('/api/v1/me/today', $this->app('0.0.1'))->assertStatus(401);
    }

    public function test_app_config_is_public_and_lists_the_versions(): void
    {
        config(['app_versions.latest' => ['android' => '1.6.0', 'ios' => null]]);

        $this->getJson('/api/v1/app-config')
            ->assertOk()
            ->assertJsonPath('min_supported.android', '1.4.0')
            ->assertJsonPath('latest.android', '1.6.0')
            ->assertJsonPath('store_url.android', 'https://play.example.test/app');
    }
}
