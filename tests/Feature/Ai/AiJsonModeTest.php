<?php

namespace Tests\Feature\Ai;

use App\Exceptions\Ai\AiGenerationException;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Progress\WeightInsightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The provider's JSON mode (Groq): a reasoning model that runs out of output
 * tokens produces a cut-off object and the provider answers 400
 * `json_validate_failed`. The client sizes the request for that, retries once
 * as plain text when it still happens, and never logs prompt or output text.
 */
class AiJsonModeTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_PROMPT = 'PROMPT-SECRET-سلمى-0599123456';

    private const SECRET_OUTPUT = 'PARTIAL-OUTPUT-SECRET';

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.base_url' => 'https://fake-llm.test', 'ai.api_key' => 'test-key', 'ai.model' => 'openai/gpt-oss-120b', 'ai.timeout' => 5]);
    }

    private function ok(string $content): array
    {
        return ['choices' => [['message' => ['content' => $content]]]];
    }

    /** The provider's 400 body. */
    private function failedBody(): array
    {
        return ['error' => [
            'message' => "Failed to validate JSON. Please adjust your prompt. See 'failed_generation' for more details.",
            'type' => 'invalid_request_error',
            'code' => 'json_validate_failed',
            'failed_generation' => self::SECRET_OUTPUT.' {"text": "cut off',
        ]];
    }

    /** A fake provider that answers the given bodies in turn, the first `$failures` of them with json_validate_failed. */
    private function fakeProvider(int $failures, array ...$then): void
    {
        $sequence = Http::sequence();
        for ($i = 0; $i < $failures; $i++) {
            $sequence->push($this->failedBody(), 400);
        }
        foreach ($then as $body) {
            $sequence->push($body);
        }

        Http::fake(['*' => $sequence]);
    }

    private function ask(?OpenAiCompatibleClient $client = null, array $options = ['max_completion_tokens' => 300]): array
    {
        return ($client ?? new OpenAiCompatibleClient)->chatJson([
            ['role' => 'system', 'content' => 'Reply as JSON: {"text": "..."}'],
            ['role' => 'user', 'content' => self::SECRET_PROMPT],
        ], $options);
    }

    // ---- sizing the request -----------------------------------------------------------------------------

    public function test_a_reasoning_model_gets_low_effort_and_room_to_think(): void
    {
        Http::fake(['*' => Http::response($this->ok('{"text":"a"}'))]);

        $this->ask(options: ['max_completion_tokens' => 300]);

        Http::assertSent(fn (Request $r) => $r['response_format'] === ['type' => 'json_object']
            && $r['reasoning_effort'] === 'low'
            && $r['max_completion_tokens'] === 300 + OpenAiCompatibleClient::REASONING_HEADROOM_TOKENS);
    }

    public function test_a_call_with_no_budget_gets_the_default_one_plus_headroom(): void
    {
        Http::fake(['*' => Http::response($this->ok('{"text":"a"}'))]);

        $this->ask(options: []);

        Http::assertSent(fn (Request $r) => $r['max_completion_tokens'] === OpenAiCompatibleClient::DEFAULT_ANSWER_TOKENS + OpenAiCompatibleClient::REASONING_HEADROOM_TOKENS);
    }

    public function test_a_caller_that_sized_for_reasoning_itself_is_left_alone(): void
    {
        Http::fake(['*' => Http::response($this->ok('{"text":"a"}'))]);

        $this->ask(options: ['max_completion_tokens' => 6000, 'reasoning_effort' => 'low']);

        Http::assertSent(fn (Request $r) => $r['max_completion_tokens'] === 6000 && $r['reasoning_effort'] === 'low');
    }

    public function test_a_model_that_does_not_reason_is_not_given_reasoning_settings(): void
    {
        config(['ai.model' => 'llama-3.3-70b-versatile']);
        Http::fake(['*' => Http::response($this->ok('{"text":"a"}'))]);

        $this->ask(options: ['max_completion_tokens' => 300]);

        Http::assertSent(fn (Request $r) => ! isset($r['reasoning_effort']) && $r['max_completion_tokens'] === 300);
    }

    // ---- the retry --------------------------------------------------------------------------------------------

    public function test_json_validate_failed_is_retried_once_without_json_mode_and_the_object_is_taken_from_the_text(): void
    {
        $this->fakeProvider(1, $this->ok("Here you go:\n```json\n{\"text\": \"مرحبا {x}\"}\n```"));

        $client = new OpenAiCompatibleClient;
        $reply = $this->ask($client);

        $this->assertSame(['text' => 'مرحبا {x}'], $reply);
        $this->assertTrue($client->lastCallRetried);

        $sent = Http::recorded();
        $this->assertCount(2, $sent);
        $this->assertArrayHasKey('response_format', $sent[0][0]->data());
        $this->assertArrayNotHasKey('response_format', $sent[1][0]->data(), 'the retry is plain text');
        $this->assertSame($sent[0][0]['messages'], $sent[1][0]['messages'], 'same prompt');
        $this->assertSame($sent[0][0]['max_completion_tokens'], $sent[1][0]['max_completion_tokens']);
    }

    public function test_a_normal_reply_is_one_request_and_not_marked_retried(): void
    {
        Http::fake(['*' => Http::response($this->ok('{"text":"a"}'))]);

        $client = new OpenAiCompatibleClient;
        $this->ask($client);

        Http::assertSentCount(1);
        $this->assertFalse($client->lastCallRetried);
    }

    public function test_when_the_retry_fails_too_the_caller_gets_the_exception_it_always_got(): void
    {
        $this->fakeProvider(2);

        try {
            $this->ask();
            $this->fail('expected an AiGenerationException');
        } catch (AiGenerationException $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertStringContainsString('Failed to validate JSON', $e->getMessage());
        }

        Http::assertSentCount(2);
    }

    public function test_when_the_retry_text_holds_no_json_object_it_is_an_exception(): void
    {
        $this->fakeProvider(1, $this->ok('Sorry, I cannot do that.'));

        $this->expectException(AiGenerationException::class);
        $this->expectExceptionMessage('valid JSON object');

        $this->ask();
    }

    public function test_when_the_retry_has_a_server_error_the_status_is_kept(): void
    {
        Http::fake(['*' => Http::sequence()->push($this->failedBody(), 400)->push(null, 503)]);

        try {
            $this->ask();
            $this->fail('expected an AiGenerationException');
        } catch (AiGenerationException $e) {
            $this->assertSame(503, $e->getCode());
        }
    }

    public function test_another_400_is_not_retried(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'bad model', 'code' => 'model_not_found']], 400)]);

        try {
            $this->ask();
            $this->fail('expected an AiGenerationException');
        } catch (AiGenerationException $e) {
            $this->assertSame(400, $e->getCode());
        }

        Http::assertSentCount(1);
    }

    public function test_a_rate_limit_is_not_retried_here(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'slow down']], 429, ['Retry-After' => '3'])]);

        try {
            $this->ask();
            $this->fail('expected an AiGenerationException');
        } catch (AiGenerationException $e) {
            $this->assertSame(429, $e->getCode());
            $this->assertSame(3, $e->retryAfter);
        }

        Http::assertSentCount(1);
    }

    // ---- reading the object out of text -----------------------------------------------------------------------

    /** @return array<string, array{string, array<string, mixed>|null}> */
    public static function replies(): array
    {
        return [
            'plain' => ['{"a": 1}', ['a' => 1]],
            'in a fence' => ["```json\n{\"a\": 1}\n```", ['a' => 1]],
            'prose around' => ['Sure! {"a": "x"} Hope it helps.', ['a' => 'x']],
            'braces inside a string' => ['{"a": "}{ not the end"}', ['a' => '}{ not the end']],
            'an escaped quote' => ['{"a": "say \\"hi\\" }"} tail', ['a' => 'say "hi" }']],
            'nested' => ['{"a": {"b": [1, {"c": 2}]}}', ['a' => ['b' => [1, ['c' => 2]]]]],
            'a broken block first' => ['{"a": oops} then {"b": 2}', ['b' => 2]],
            'cut off' => ['{"a": "never ends', null],
            'no object' => ['nothing here', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('replies')]
    public function test_the_first_complete_object_is_found(string $content, ?array $expected): void
    {
        Http::fake(['*' => Http::response($this->ok($content))]);

        if ($expected === null) {
            $this->expectException(AiGenerationException::class);
        }

        $this->assertSame($expected, $this->ask());
    }

    // ---- logs -------------------------------------------------------------------------------------------------

    public function test_the_log_has_codes_and_sizes_but_no_prompt_and_no_output(): void
    {
        Log::spy();
        $this->fakeProvider(1, $this->ok('{"text":"a"}'));

        $this->ask();

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context = []): bool {
            $this->assertSame('json_validate_failed', $context['error_code']);
            $this->assertSame('openai/gpt-oss-120b', $context['model']);
            $this->assertSame(300 + OpenAiCompatibleClient::REASONING_HEADROOM_TOKENS, $context['max_tokens']);
            $this->assertSame(mb_strlen(self::SECRET_OUTPUT.' {"text": "cut off'), $context['failed_generation_length']);

            return true;
        });
    }

    public function test_nothing_logged_anywhere_holds_the_prompt_or_the_models_text(): void
    {
        Log::spy();
        $this->fakeProvider(2);

        try {
            $this->ask();
        } catch (AiGenerationException $e) {
            // The exception message is what callers log: it must be clean too.
            Log::warning('caller', ['error' => $e->getMessage()]);
        }

        $everything = '';
        // Every call the client made on the logger, at any level.
        foreach (['warning', 'info', 'error', 'debug', 'notice'] as $level) {
            try {
                Log::shouldHaveReceived($level)->withArgs(function (...$args) use (&$everything): bool {
                    $everything .= json_encode($args, JSON_UNESCAPED_UNICODE);

                    return true;
                });
            } catch (\Mockery\Exception\InvalidCountException) {
                // Nothing was logged at this level.
            }
        }

        $this->assertNotSame('', $everything);
        $this->assertStringNotContainsString(self::SECRET_PROMPT, $everything);
        $this->assertStringNotContainsString('سلمى', $everything);
        $this->assertStringNotContainsString(self::SECRET_OUTPUT, $everything);
    }

    // ---- the features that use it -------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function facts(): array
    {
        return ['change_kg' => -1.2, 'days' => 14, 'likely_water_fluctuation' => false, 'consistent_with_plan' => false];
    }

    public function test_the_weight_insight_succeeds_after_the_retry(): void
    {
        $this->fakeProvider(1, $this->ok('بعض الكلام: {"text": "نزل وزنك شوي. وأخصائيتك بتتابع معك."}'));

        [$text, $reason] = app(WeightInsightService::class)->words($this->facts());

        $this->assertSame('نزل وزنك شوي. وأخصائيتك بتتابع معك.', $text);
        $this->assertNull($reason, 'the model\'s own words, not the fallback');
    }

    public function test_the_weight_insight_falls_back_when_both_attempts_fail(): void
    {
        $this->fakeProvider(2);

        [$text, $reason] = app(WeightInsightService::class)->words($this->facts());

        $this->assertSame('provider_failed', $reason);
        $this->assertSame(app(WeightInsightService::class)->fallbackText($this->facts()), $text);
    }

    // ---- ai:ping ----------------------------------------------------------------------------------------------

    public function test_ping_reports_ok_with_the_model_and_latency(): void
    {
        Http::fake(['*' => Http::response($this->ok('{"ok": true}'))]);

        $this->artisan('ai:ping')
            ->expectsOutputToContain('ok profile=default model=openai/gpt-oss-120b latency=')
            ->assertSuccessful();

        Http::assertSent(function (Request $r): bool {
            $text = json_encode($r->data(), JSON_UNESCAPED_UNICODE);

            return str_contains($text, 'ping') && ! str_contains($text, 'سلمى') && $r['response_format'] === ['type' => 'json_object'];
        });
    }

    public function test_ping_says_when_json_mode_was_refused_and_the_retry_worked(): void
    {
        $this->fakeProvider(1, $this->ok('{"ok": true}'));

        $this->artisan('ai:ping')->expectsOutputToContain('json_mode=refused_retried_as_text')->assertSuccessful();
    }

    public function test_ping_says_when_json_mode_was_accepted(): void
    {
        Http::fake(['*' => Http::response($this->ok('{"ok": true}'))]);

        $this->artisan('ai:ping')->expectsOutputToContain('json_mode=accepted')->assertSuccessful();
    }

    public function test_ping_fails_with_the_providers_reason_and_no_prompt(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid API Key']], 401)]);

        $this->artisan('ai:ping')
            ->expectsOutputToContain('fail profile=default model=openai/gpt-oss-120b')
            ->assertFailed();
    }

    public function test_the_failure_line_carries_the_providers_message(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid API Key']], 401)]);

        $this->artisan('ai:ping')->expectsOutputToContain('AI provider returned HTTP 401. Invalid API Key')->assertFailed();
    }

    public function test_ping_fails_cleanly_when_nothing_is_configured(): void
    {
        config(['ai.base_url' => '', 'ai.api_key' => '']);
        Http::fake();

        $this->artisan('ai:ping')->expectsOutputToContain('reason=not_configured')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_ping_can_check_the_summary_profile(): void
    {
        config(['ai.summary' => ['base_url' => 'https://summary-llm.test', 'api_key' => 'k2', 'model' => 'llama-3.3-70b-versatile', 'timeout' => 5]]);
        Http::fake(['*' => Http::response($this->ok('{"ok": true}'))]);

        $this->artisan('ai:ping', ['--profile' => 'summary'])->expectsOutputToContain('ok profile=summary model=llama-3.3-70b-versatile')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://summary-llm.test'));
    }

    public function test_ping_rejects_an_unknown_profile(): void
    {
        $this->artisan('ai:ping', ['--profile' => 'nope'])->assertExitCode(2);
    }
}
