<?php

namespace Tests\Feature\System;

use App\Models\PatientConsent;
use App\Models\Subscriber;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\TrustedProxies;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/** B1: X-Forwarded-* is believed only from the configured proxy. */
class TrustedProxiesTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private const PROXY = '10.20.0.5';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function acceptsConsentForPatients(): bool
    {
        return false;
    }

    private function viaProxy(string $remote): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $remote])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'healthylife.example', 'X-Forwarded-Port' => '443']);
    }

    public function test_behind_the_configured_proxy_the_real_client_ip_and_https_are_used(): void
    {
        config(['trustedproxy.proxies' => [self::PROXY]]);
        $nutritionist = User::factory()->nutritionist()->create();
        $patient = Subscriber::factory()->active()->forNutritionist($nutritionist)->create();

        $this->viaProxy(self::PROXY)
            ->postJson('/api/v1/me/consent', ['version' => config('patient_app.consent.version')], $this->bearerFor($patient->user))
            ->assertCreated();
        $this->assertSame('203.0.113.7', PatientConsent::where('user_id', $patient->user_id)->value('ip_address'));

        Subscriber::factory()->count(2)->active()->forNutritionist($nutritionist)->create();
        $links = $this->viaProxy(self::PROXY)->getJson('/api/v1/clients?per_page=1', $this->bearerFor($nutritionist))->assertOk()->json('links');
        $this->assertStringStartsWith('https://healthylife.example/api/v1/clients', $links['next']);
    }

    public function test_forwarded_headers_from_anyone_else_are_ignored(): void
    {
        config(['trustedproxy.proxies' => [self::PROXY]]);
        $nutritionist = User::factory()->nutritionist()->create();
        $patient = Subscriber::factory()->active()->forNutritionist($nutritionist)->create();

        $this->viaProxy('10.20.0.99')
            ->postJson('/api/v1/me/consent', ['version' => config('patient_app.consent.version')], $this->bearerFor($patient->user))
            ->assertCreated();

        $this->assertSame('10.20.0.99', PatientConsent::where('user_id', $patient->user_id)->value('ip_address'));
    }

    public function test_diagnostics_show_what_the_app_sees_and_whether_the_proxy_matches(): void
    {
        $admin = User::factory()->admin()->create();

        config(['trustedproxy.proxies' => null]);
        $this->viaProxy(self::PROXY)->getJson('/api/v1/system/scheduler-status', $this->bearerFor($admin))
            ->assertOk()
            ->assertJsonPath('request.remote_addr', self::PROXY)
            ->assertJsonPath('request.client_ip', self::PROXY)
            ->assertJsonPath('request.scheme', 'http')
            ->assertJsonPath('request.trusted_proxy_match', false);

        config(['trustedproxy.proxies' => [self::PROXY]]);
        $this->viaProxy(self::PROXY)->getJson('/api/v1/system/scheduler-status', $this->bearerFor($admin))
            ->assertJsonPath('request.client_ip', '203.0.113.7')
            ->assertJsonPath('request.scheme', 'https')
            ->assertJsonPath('request.trusted_proxy_match', true);

        // The proxy moved (e.g. a redeploy): a mismatch is visible at a glance.
        $this->viaProxy('10.20.0.6')->getJson('/api/v1/system/scheduler-status', $this->bearerFor($admin))
            ->assertJsonPath('request.remote_addr', '10.20.0.6')
            ->assertJsonPath('request.trusted_proxy_match', false);
    }

    public function test_a_wildcard_or_garbage_is_refused(): void
    {
        foreach (['*', '**', '10.0.0.1,*', 'proxy.taqat', '10.0.0.0/40'] as $value) {
            try {
                TrustedProxies::parse($value);
                $this->fail("accepted {$value}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('TRUSTED_PROXIES', $e->getMessage());
            }
        }

        $this->assertSame(['10.0.0.1', '172.18.0.0/16', '::1'], TrustedProxies::parse(' 10.0.0.1, 172.18.0.0/16 ,::1'));
        $this->assertNull(TrustedProxies::parse(''));
        $this->assertNull(TrustedProxies::parse(null));
    }

    public function test_the_boot_check_refuses_a_wildcard(): void
    {
        config(['trustedproxy.proxies' => '*']);

        $this->expectException(RuntimeException::class);
        (new AppServiceProvider($this->app))->boot();
    }
}
