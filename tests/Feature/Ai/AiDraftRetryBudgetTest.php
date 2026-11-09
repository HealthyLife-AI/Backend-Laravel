<?php

namespace Tests\Feature\Ai;

use App\Models\Food;
use App\Models\HealthProfile;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\MealPlans\AiDraftPlanService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * One AI draft has 45 s in total. A 429 from the provider's per-minute token limit is waited out when its
 * Retry-After plus a minimal request still fits in what is left, otherwise the draft falls back at once; an
 * answer that fails validation says which rule it broke.
 */
class AiDraftRetryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private User $nutritionist;

    private Subscriber $subscriber;

    /** @var list<int> */
    private array $foods = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sleep::fake(syncWithCarbon: true);
        Carbon::setTestNow(Carbon::now());   // a frozen clock that only sleeping and the fake provider move
        config(['ai.base_url' => 'https://fake-llm.test', 'ai.api_key' => 'k', 'ai.model' => 'openai/gpt-oss-120b', 'ai.timeout' => 12]);

        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->subscriber = Subscriber::factory()->active()->forNutritionist($this->nutritionist)->create();
        HealthProfile::create(['subscriber_id' => $this->subscriber->id, 'weight_kg' => 70, 'height_cm' => 170, 'age' => 30, 'gender' => 'female', 'activity_level' => 'light', 'daily_calorie_needs' => 1900]);
        $this->foods = Food::factory()->count(6)->create(['allergens' => []])->pluck('id')->all();
    }

    private function clock(): float
    {
        return (float) now()->format('U.u');
    }

    private function draft(): \App\Models\MealPlan
    {
        return app(AiDraftPlanService::class)->generateDraft($this->subscriber, $this->nutritionist);
    }

    private function item(int $foodId, int|string $grams = 150, array $alternatives = []): array
    {
        return ['food_id' => $foodId, 'quantity_grams' => $grams, 'alternatives' => $alternatives];
    }

    private function completion(array $payload): array
    {
        return ['choices' => [['message' => ['content' => json_encode($payload)]]]];
    }

    private function validAnswer(): array
    {
        [$a, $b, $c, $d] = $this->foods;

        return $this->completion(['meals' => [
            ['name' => 'breakfast', 'items' => [$this->item($a)]],
            ['name' => 'snack', 'items' => [$this->item($b, 80)]],
            ['name' => 'lunch', 'items' => [$this->item($c, 250)]],
            ['name' => 'dinner', 'items' => [$this->item($d, 200)]],
        ]]);
    }

    private function rateLimited(int $retryAfter): \Illuminate\Http\Client\Response|\GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['error' => ['message' => 'Rate limit reached for model in organization on tokens per minute (TPM): Limit 8000']], 429, ['Retry-After' => (string) $retryAfter]);
    }

    // ---- 429 and the budget ------------------------------------------------------------------------------------------------------

    public function test_the_draft_budget_is_45_seconds_and_other_ai_calls_keep_their_own_timeout(): void
    {
        $this->assertSame(45, AiDraftPlanService::TOTAL_BUDGET_SECONDS);

        $timeouts = [];
        Http::fake(function ($request, $options) use (&$timeouts) {
            $timeouts[] = (float) $options['timeout'];

            return Http::response($this->validAnswer());
        });
        $this->draft();
        $this->assertEquals(12, $timeouts[0], 'a request is still capped by OPENAI_TIMEOUT; the 45 s is the total');

        // The plan summary, weight insight and weekly summary use no budget: the configured timeout alone.
        $timeouts = [];
        Http::fake(function ($request, $options) use (&$timeouts) {
            $timeouts[] = (float) $options['timeout'];

            return Http::response($this->completion(['text' => 'x']));
        });
        app(\App\Services\Ai\OpenAiCompatibleClient::class)->chatJson([['role' => 'user', 'content' => 'x']]);
        $this->assertEquals(12, $timeouts[0]);
        $this->assertNull(app(\App\Services\Ai\OpenAiCompatibleClient::class)->remainingSeconds());
    }

    public function test_a_429_with_a_short_retry_after_is_waited_out_and_the_second_try_succeeds(): void
    {
        $sent = 0;
        Http::fake(function () use (&$sent) {
            return ++$sent === 1 ? $this->rateLimited(6) : Http::response($this->validAnswer());
        });
        $start = $this->clock();

        $plan = $this->draft();

        $this->assertFalse($plan->is_ai_fallback, 'the model drafted it');
        $this->assertSame(2, $sent);
        Sleep::assertSleptTimes(1);
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 6, 1);
        $this->assertEqualsWithDelta(6, $this->clock() - $start, 0.5);
    }

    public function test_a_longer_retry_after_that_still_fits_is_waited_for_too(): void
    {
        $sent = 0;
        Http::fake(function () use (&$sent) {
            return ++$sent === 1 ? $this->rateLimited(30) : Http::response($this->validAnswer());   // 30 + 6 <= 45
        });

        $plan = $this->draft();

        $this->assertFalse($plan->is_ai_fallback);
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 30, 1);
    }

    public function test_a_429_whose_retry_after_does_not_fit_falls_back_at_once_without_waiting(): void
    {
        Http::fake(fn () => $this->rateLimited(40));   // 40 + 6 = 46 > 45
        Log::spy();
        $start = $this->clock();

        $plan = $this->draft();

        $this->assertTrue($plan->is_ai_fallback);
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
        $this->assertLessThan(1, $this->clock() - $start);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => ($context['reason'] ?? null) === 'request_failed'
            && $context['status'] === 429 && $context['retry_after'] === 40 && $context['attempt'] === 1 && str_contains($context['error'], 'tokens per minute'))->once();
    }

    public function test_a_retry_after_that_no_longer_fits_after_a_slow_request_falls_back_at_once(): void
    {
        Http::fake(function () {
            Carbon::setTestNow(now()->addSeconds(12));   // a slow 429: 33 s left, and 30 + 6 = 36 > 33

            return $this->rateLimited(30);
        });
        $start = $this->clock();

        $plan = $this->draft();

        $this->assertTrue($plan->is_ai_fallback);
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
        $this->assertEqualsWithDelta(12, $this->clock() - $start, 0.5);
    }

    public function test_a_provider_that_keeps_answering_429_uses_up_the_budget_and_never_goes_past_it(): void
    {
        $timeouts = [];
        Http::fake(function ($request, $options) use (&$timeouts) {
            $timeouts[] = (float) $options['timeout'];
            Carbon::setTestNow(now()->addMilliseconds((int) round($options['timeout'] * 1000)));   // every request takes its whole timeout

            return $this->rateLimited(6);
        });
        $start = $this->clock();

        $plan = $this->draft();

        $this->assertTrue($plan->is_ai_fallback);
        $this->assertCount(3, $timeouts, 'at most three requests when the provider keeps refusing');
        $this->assertEquals(12, $timeouts[0]);
        $this->assertEquals(12, $timeouts[1]);
        $this->assertEqualsWithDelta(9, $timeouts[2], 0.05, 'the last request gets only what is left');
        $this->assertLessThanOrEqual(45.01, $this->clock() - $start, 'the draft never holds the worker past the budget');
        Sleep::assertSleptTimes(2);
    }

    public function test_a_429_without_a_retry_after_falls_back_at_once(): void
    {
        Http::fake(fn () => Http::response(['error' => ['message' => 'rate limit']], 429));

        $plan = $this->draft();

        $this->assertTrue($plan->is_ai_fallback);
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_a_server_error_is_not_retried(): void
    {
        Http::fake(fn () => Http::response(null, 500));

        $this->assertTrue($this->draft()->is_ai_fallback);
        Http::assertSentCount(1);
    }

    // ---- why an answer was refused -----------------------------------------------------------------------------------------------

    /** @return array<string, array{0: callable(array<int>): array<string, mixed>, 1: string, 2: string}> */
    public static function badAnswers(): array
    {
        $item = fn (int $id, int|string $grams = 150, array $alternatives = []) => ['food_id' => $id, 'quantity_grams' => $grams, 'alternatives' => $alternatives];

        return [
            'no meals' => [fn (array $f) => ['meals' => []], 'meals_missing_or_empty', 'meals'],
            'a meal name that is not allowed' => [fn (array $f) => ['meals' => [['name' => 'brunch', 'items' => [$item($f[0])]]]], 'meal_name_not_allowed', 'meals[0].name'],
            'a meal named twice' => [fn (array $f) => ['meals' => [['name' => 'lunch', 'items' => [$item($f[0])]], ['name' => 'lunch', 'items' => [$item($f[1])]]]], 'meal_name_repeated', 'meals[1].name'],
            'a meal with no items' => [fn (array $f) => ['meals' => [['name' => 'breakfast', 'items' => []]]], 'meal_has_no_items', 'meals[0].items'],
            'five items in one meal' => [fn (array $f) => ['meals' => [['name' => 'lunch', 'items' => array_map(fn ($id) => $item($id), [...$f, $f[0]])]]], 'too_many_items_in_a_meal', 'meals[0].items'],
            'a planned food that is not on the menu' => [fn (array $f) => ['meals' => [['name' => 'breakfast', 'items' => [$item(999999)]]]], 'planned_item_food_id_not_on_the_menu', 'meals[0].items[0]'],
            'a planned food id that is not a number' => [fn (array $f) => ['meals' => [['name' => 'breakfast', 'items' => [['food_id' => 'abc', 'quantity_grams' => 100]]]]], 'planned_item_food_id_not_an_integer', 'meals[0].items[0]'],
            'a planned quantity above 500 g' => [fn (array $f) => ['meals' => [['name' => 'breakfast', 'items' => [$item($f[0], 650)]]]], 'planned_item_quantity_out_of_range', 'meals[0].items[0]'],
            'a planned item that is not an object' => [fn (array $f) => ['meals' => [['name' => 'breakfast', 'items' => ['rice']]]], 'planned_item_not_an_object', 'meals[0].items[0]'],
            'the same planned food in two meals' => [fn (array $f) => ['meals' => [['name' => 'breakfast', 'items' => [$item($f[0])]], ['name' => 'lunch', 'items' => [$item($f[0])]]]], 'food_planned_twice_in_the_day', 'meals[1].items[0]'],
            'an alternative that is not on the menu' => [fn (array $f) => ['meals' => [['name' => 'breakfast', 'items' => [$item($f[0], 100, [$item($f[1]), $item(999999)])]]]], 'alternative_food_id_not_on_the_menu', 'meals[0].items[0].alternatives[1]'],
            'an alternative quantity below 10 g' => [fn (array $f) => ['meals' => [['name' => 'breakfast', 'items' => [$item($f[0], 100, [$item($f[1], 5)])]]]], 'alternative_quantity_out_of_range', 'meals[0].items[0].alternatives[0]'],
        ];
    }

    /** @param  callable(array<int>): array<string, mixed>  $build */
    #[\PHPUnit\Framework\Attributes\DataProvider('badAnswers')]
    public function test_a_refused_answer_logs_which_rule_failed_and_where(callable $build, string $rule, string $where): void
    {
        Http::fake(fn () => Http::response($this->completion($build($this->foods))));
        Log::spy();

        $plan = $this->draft();

        $this->assertTrue($plan->is_ai_fallback);
        Http::assertSentCount(2);   // asked once more, as before

        $logged = null;
        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []) use (&$logged) {
            if (($context['reason'] ?? null) === 'invalid_response') {
                $logged = $context;

                return true;
            }

            return false;
        })->once();

        $this->assertSame($rule, $logged['rule']);
        $this->assertSame($where, $logged['where']);
        $this->assertSame(2, $logged['attempt']);
        $this->assertSame(['meals'], $logged['keys']);
        $this->assertIsString($logged['excerpt']);
        $this->assertLessThanOrEqual(300, mb_strlen($logged['excerpt']));

        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []) => str_contains($message, 'asking once more') && ($context['rule'] ?? null) === $rule)->once();
    }

    public function test_the_excerpt_shows_the_shape_and_never_what_the_model_wrote_in_a_text_field(): void
    {
        [$a] = $this->foods;
        Http::fake(fn () => Http::response($this->completion(['meals' => [['name' => 'breakfast', 'items' => [
            ['food_id' => $a, 'quantity_grams' => 650, 'note' => 'Salma Haddad 0599123456', 'سلمى' => 'x', 'alternatives' => []],
        ]]]])));
        Log::spy();

        $this->draft();

        $logged = null;
        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []) use (&$logged) {
            $logged = ($context['reason'] ?? null) === 'invalid_response' ? $context : $logged;

            return ($context['reason'] ?? null) === 'invalid_response';
        })->once();

        $this->assertSame('planned_item_quantity_out_of_range', $logged['rule']);
        $this->assertStringContainsString('"quantity_grams":650', $logged['excerpt'], 'numbers are kept: they are the evidence');
        $this->assertStringContainsString('"note":"string(23)"', $logged['excerpt'], 'text becomes its length');
        $this->assertStringContainsString('key(', $logged['excerpt'], 'an odd key is hidden too');
        foreach (['Salma', 'Haddad', '0599', 'سلمى'] as $personal) {
            $this->assertStringNotContainsString($personal, json_encode($logged, JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_a_valid_answer_on_the_second_try_after_a_refused_first_one_is_used(): void
    {
        $sent = 0;
        Http::fake(function () use (&$sent) {
            return Http::response(++$sent === 1 ? $this->completion(['meals' => [['name' => 'brunch', 'items' => []]]]) : $this->validAnswer());
        });

        $this->assertFalse($this->draft()->is_ai_fallback);
        $this->assertSame(2, $sent);
    }

    // ---- paid tier: environment only ----------------------------------------------------------------------------------------------

    public function test_switching_provider_model_key_and_timeout_is_configuration_only(): void
    {
        config(['ai.base_url' => 'https://paid.example/openai/v1', 'ai.api_key' => 'paid-key', 'ai.model' => 'gpt-4.1-mini', 'ai.timeout' => 30]);
        $seen = [];
        Http::fake(function ($request, $options) use (&$seen) {
            $seen = ['url' => $request->url(), 'auth' => $request->header('Authorization')[0] ?? null, 'model' => $request['model'], 'timeout' => (float) $options['timeout'], 'body' => $request->data()];

            return Http::response($this->validAnswer());
        });

        $plan = $this->draft();

        $this->assertFalse($plan->is_ai_fallback);
        $this->assertSame('https://paid.example/openai/v1/chat/completions', $seen['url']);
        $this->assertSame('Bearer paid-key', $seen['auth']);
        $this->assertSame('gpt-4.1-mini', $seen['model']);
        $this->assertEquals(30, $seen['timeout']);
        $this->assertArrayNotHasKey('reasoning_effort', $seen['body'], 'only the gpt-oss models get a reasoning effort');
    }

    public function test_the_environment_names_in_the_docs_are_the_ones_the_config_reads(): void
    {
        $config = (string) file_get_contents(base_path('config/ai.php'));
        $docs = (string) file_get_contents(base_path('docs/production.md'));

        foreach (['OPENAI_BASE_URL', 'OPENAI_API_KEY', 'OPENAI_MODEL', 'OPENAI_TIMEOUT', 'OPENAI_SUMMARY_BASE_URL', 'OPENAI_SUMMARY_API_KEY', 'OPENAI_SUMMARY_MODEL', 'OPENAI_SUMMARY_TIMEOUT', 'AI_PRICE_PER_MILLION_INPUT', 'AI_PRICE_PER_MILLION_OUTPUT', 'AI_PRICE_PER_MILLION_CACHED_INPUT'] as $name) {
            $this->assertStringContainsString("'{$name}'", $config, "{$name} is read by config/ai.php");
            $this->assertStringContainsString($name, $docs, "{$name} is listed in docs/production.md");
        }
    }
}
