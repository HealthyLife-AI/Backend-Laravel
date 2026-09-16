<?php

namespace Tests\Feature\System;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

class AiStatusTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_it_reports_a_configured_provider_without_revealing_the_key(): void
    {
        config([
            'ai.base_url' => 'https://api.groq.com/openai/v1',
            'ai.api_key' => 'gsk_super_secret_value',
            'ai.model' => 'openai/gpt-oss-120b',
            'ai.timeout' => 12,
        ]);

        $response = $this->getJson('/api/v1/system/ai-status', $this->bearerFor(User::factory()->nutritionist()->create()));

        $response->assertOk()
            ->assertJson([
                'configured' => true,
                'provider_host' => 'api.groq.com',
                'model' => 'openai/gpt-oss-120b',
                'timeout_seconds' => 12,
            ]);

        // The whole point of reporting a host and a model rather than the
        // config itself: an operator can confirm the environment is wired
        // up without the endpoint ever handing out the credential.
        $this->assertStringNotContainsString('gsk_super_secret_value', $response->getContent());
        $this->assertStringNotContainsString('gsk_', $response->getContent());
    }

    public function test_it_reports_an_unconfigured_provider(): void
    {
        config(['ai.base_url' => null, 'ai.api_key' => null, 'ai.model' => null]);

        $this->getJson('/api/v1/system/ai-status', $this->bearerFor(User::factory()->nutritionist()->create()))
            ->assertOk()
            ->assertJson(['configured' => false, 'provider_host' => null, 'model' => null]);
    }

    public function test_a_key_without_a_base_url_is_not_reported_as_configured(): void
    {
        config(['ai.base_url' => null, 'ai.api_key' => 'gsk_key_but_nowhere_to_send_it']);

        $this->getJson('/api/v1/system/ai-status', $this->bearerFor(User::factory()->nutritionist()->create()))
            ->assertOk()
            ->assertJson(['configured' => false]);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/system/ai-status')->assertUnauthorized();
    }

    // --- summary profile (S5-03 follow-up) ------------------------------

    public function test_it_reports_a_distinct_summary_key_without_revealing_it(): void
    {
        config([
            'ai.api_key' => 'gsk_draft_secret',
            'ai.summary.base_url' => 'https://api.groq.com/openai/v1',
            'ai.summary.api_key' => 'gsk_summary_secret',
            'ai.summary.model' => 'openai/gpt-oss-120b',
        ]);

        $response = $this->getJson('/api/v1/system/ai-status', $this->bearerFor(User::factory()->nutritionist()->create()));

        $response->assertOk()->assertJson([
            'summary' => [
                'configured' => true,
                'provider_host' => 'api.groq.com',
                'model' => 'openai/gpt-oss-120b',
                'shares_draft_key' => false,
            ],
        ]);
        $this->assertStringNotContainsString('gsk_summary_secret', $response->getContent());
    }

    public function test_it_reports_the_summary_profile_as_sharing_the_draft_key_when_unset(): void
    {
        config([
            'ai.api_key' => 'gsk_draft_secret',
            'ai.summary.base_url' => null,
            'ai.summary.api_key' => null,
            'ai.summary.model' => null,
        ]);

        // Mirrors config/ai.php's own env() fallback: an unset summary key
        // literally IS the draft key at this point, so the comparison
        // reads true — this is the "still sharing one quota" case.
        config(['ai.summary.api_key' => config('ai.api_key')]);

        $this->getJson('/api/v1/system/ai-status', $this->bearerFor(User::factory()->nutritionist()->create()))
            ->assertOk()
            ->assertJson(['summary' => ['shares_draft_key' => true]]);
    }

    public function test_an_unconfigured_summary_profile_is_reported_separately_from_the_draft(): void
    {
        config([
            'ai.base_url' => 'https://api.groq.com/openai/v1',
            'ai.api_key' => 'gsk_draft_secret',
            'ai.model' => 'openai/gpt-oss-120b',
            'ai.summary.base_url' => null,
            'ai.summary.api_key' => null,
            'ai.summary.model' => null,
        ]);

        $this->getJson('/api/v1/system/ai-status', $this->bearerFor(User::factory()->nutritionist()->create()))
            ->assertOk()
            ->assertJson([
                'configured' => true,
                'summary' => ['configured' => false, 'provider_host' => null, 'model' => null],
            ]);
    }
}
