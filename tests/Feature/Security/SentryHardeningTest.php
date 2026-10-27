<?php

namespace Tests\Feature\Security;

use App\Exceptions\Ai\AiGenerationException;
use App\Exceptions\ApiCodeException;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\State\Hub;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * The real SDK pipeline with this app's own Sentry config and a recording transport:
 * what a thrown exception looks like when it arrives, and what the config switches off.
 */
class SentryHardeningTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    /** @return array{0: Hub, 1: object{events: list<Event>}} */
    private function hub(): array
    {
        $transport = new class implements TransportInterface
        {
            /** @var list<Event> */
            public array $events = [];

            public function send(Event $event): Result
            {
                $this->events[] = $event;

                return new Result(ResultStatus::success(), $event);
            }

            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success());
            }
        };

        $options = array_intersect_key(config('sentry'), array_flip(['send_default_pii', 'before_send', 'before_send_transaction', 'before_breadcrumb', 'max_request_body_size', 'traces_sample_rate', 'profiles_sample_rate']));
        $client = ClientBuilder::create($options + ['dsn' => 'https://public@o0.ingest.example.invalid/1'])->setTransport($transport)->getClient();

        return [new Hub($client), $transport];
    }

    // ---- the config --------------------------------------------------------------------------------

    public function test_the_config_switches_everything_risky_off(): void
    {
        $this->assertFalse(config('sentry.send_default_pii'));
        $this->assertSame(0.0, config('sentry.traces_sample_rate'), 'tracing off by default');
        $this->assertSame(0.0, config('sentry.profiles_sample_rate'));
        $this->assertSame('never', config('sentry.max_request_body_size'));
        $this->assertFalse(config('sentry.breadcrumbs.sql_bindings'));
        $this->assertFalse(config('sentry.tracing.sql_bindings'));
        $this->assertFalse(config('sentry.enable_logs'));
        $this->assertSame([\App\Support\SentryScrubber::class, 'beforeSend'], config('sentry.before_send'));
        $this->assertSame([\App\Support\SentryScrubber::class, 'beforeBreadcrumb'], config('sentry.before_breadcrumb'));
        $this->assertSame([\App\Support\SentryScrubber::class, 'beforeSendTransaction'], config('sentry.before_send_transaction'));
    }

    public function test_no_dsn_is_set_by_default_and_the_environment_comes_from_the_env(): void
    {
        $this->assertEmpty(config('sentry.dsn'));
        $this->assertSame(config('app.env'), config('sentry.environment'), 'APP_ENV unless SENTRY_ENVIRONMENT says otherwise');
        $this->assertStringNotContainsString('ingest.sentry.io', file_get_contents(base_path('.env.example')));
        $this->assertMatchesRegularExpression('/^SENTRY_LARAVEL_DSN=$/m', file_get_contents(base_path('.env.example')));
    }

    // ---- what arrives ---------------------------------------------------------------------------------

    public function test_a_thrown_exception_with_a_phone_an_email_and_arabic_text_arrives_scrubbed(): void
    {
        [$hub, $transport] = $this->hub();

        $hub->captureException(new \RuntimeException('Cannot notify salma@example.com on +970 59 912 3456 about: أعاني من حساسية القمح'));

        $this->assertCount(1, $transport->events);
        $message = $transport->events[0]->getExceptions()[0]->getValue();
        foreach (['salma@example.com', '912 3456', '970', 'حساسية'] as $secret) {
            $this->assertStringNotContainsString($secret, $message);
        }
        $this->assertSame('Cannot notify [email] on [phone] about: [ar-text]', $message);
        $this->assertSame(\RuntimeException::class, $transport->events[0]->getExceptions()[0]->getType());
    }

    public function test_breadcrumbs_arrive_without_bindings_or_bodies(): void
    {
        [$hub, $transport] = $this->hub();

        $hub->addBreadcrumb(new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'sql.query', 'insert into `patient_wellbeing_logs` (`note`) values (?)', ['bindings' => ['أعاني من الأرق'], 'connectionName' => 'mysql']));
        $hub->addBreadcrumb(new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_HTTP, 'http', 'POST https://llm.test', ['request_body' => '{"messages":[{"content":"سر"}]}']));
        $hub->addBreadcrumb(new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'log', 'sent to 0599123456'));
        $hub->captureException(new \RuntimeException('boom'));

        $crumbs = $transport->events[0]->getBreadcrumbs();
        $this->assertCount(2, $crumbs, 'the body breadcrumb was dropped');
        $this->assertSame('insert into `patient_wellbeing_logs` (`note`) values (?)', $crumbs[0]->getMessage(), 'the SQL text stays');
        $this->assertArrayNotHasKey('bindings', $crumbs[0]->getMetadata());

        $everything = json_encode(array_map(fn (Breadcrumb $b) => [$b->getMessage(), $b->getMetadata()], $crumbs), JSON_UNESCAPED_UNICODE);
        foreach (['الأرق', 'messages', '0599123456', 'سر'] as $secret) {
            $this->assertStringNotContainsString($secret, $everything);
        }
    }

    public function test_the_request_the_user_and_the_headers_never_arrive(): void
    {
        [$hub, $transport] = $this->hub();
        $hub->configureScope(function (\Sentry\State\Scope $scope) {
            $scope->setUser(['id' => 7, 'email' => 'a@b.co', 'ip_address' => '10.0.0.1']);
            $scope->setExtra('note', 'نص حر 0599123456');
        });
        // What the SDK's request integration would have put on the event.
        $event = Event::createEvent();
        $event->setRequest([
            'url' => 'https://api.test/api/v1/me/wellbeing/2026-10-26?token=abc', 'method' => 'PUT', 'query_string' => 'token=abc',
            'data' => ['note' => 'أعاني من التوتر'], 'cookies' => ['a' => 'b'],
            'headers' => ['Authorization' => 'Bearer x', 'Cookie' => 'a=b', 'Content-Type' => 'application/json'],
        ]);
        $event->setExceptions([new \Sentry\ExceptionDataBag(new \RuntimeException('x'))]);
        $hub->captureEvent($event);

        $sent = $transport->events[0];
        $json = json_encode([$sent->getRequest(), $sent->getUser(), $sent->getExtra()], JSON_UNESCAPED_UNICODE);

        $this->assertNull($sent->getUser());
        $this->assertArrayNotHasKey('data', $sent->getRequest());
        $this->assertArrayNotHasKey('cookies', $sent->getRequest());
        foreach (['التوتر', 'Bearer x', 'a=b', 'token=abc', 'a@b.co', '10.0.0.1', '0599123456', 'نص حر'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertSame('https://api.test/api/v1/me/wellbeing/2026-10-26', $sent->getRequest()['url']);
        $this->assertSame('PUT', $sent->getRequest()['method']);
    }

    // ---- the app's own exceptions and logs ------------------------------------------------------------------

    public function test_business_answers_are_not_reported_at_all(): void
    {
        $dontReport = (function () {
            return $this->dontReport;
        })->call(app(\Illuminate\Contracts\Debug\ExceptionHandler::class));

        foreach ([ApiCodeException::class, \App\Exceptions\Auth\AccountLockedException::class, \App\Exceptions\Auth\FollowUpEndedException::class] as $class) {
            $this->assertContains($class, $dontReport, $class);
        }
    }

    public function test_an_fcm_error_carries_the_status_not_the_response_body(): void
    {
        config(['firebase.project_id' => 'p']);
        $service = new class extends \App\Services\Notifications\FcmPushService
        {
            public function isConfigured(): bool
            {
                return true;
            }
        };
        $this->assertTrue(method_exists($service, 'send'));

        // The body (which can echo the device token) must never be part of the message.
        $source = file_get_contents(app_path('Services/Notifications/FcmPushService.php'));
        $this->assertStringNotContainsString('{$response->body()}', $source);
        $this->assertStringContainsString("json('error.status')", $source);
    }

    public function test_the_llm_logs_carry_shapes_and_never_the_models_text(): void
    {
        foreach (['Services/AiSummaries/WeeklySummaryService.php', 'Services/MealPlans/AiDraftPlanService.php', 'Services/Progress/WeightInsightService.php', 'Services/MealPlans/PlanSummarySuggestionService.php', 'Services/Ai/OpenAiCompatibleClient.php'] as $file) {
            $source = file_get_contents(app_path($file));

            $this->assertStringNotContainsString("'response' => \$response", $source, $file);
            $this->assertStringNotContainsString("'reply' => \$reply", $source, $file);
        }
    }

    public function test_an_ai_provider_error_message_is_scrubbed_on_its_way_out(): void
    {
        [$hub, $transport] = $this->hub();

        $hub->captureException(new AiGenerationException('AI provider returned HTTP 400. Bad input for user a@b.co 0599123456'));

        $message = $transport->events[0]->getExceptions()[0]->getValue();
        $this->assertSame('AI provider returned HTTP 400. Bad input for user [email] [phone]', $message);
    }

    public function test_a_failing_report_upload_never_puts_the_file_name_or_title_in_a_log_or_message(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['medical_reports.enabled' => true, 'medical_reports.disk' => 'reports']);
        Storage::fake('reports');
        Log::spy();

        $nutritionist = User::factory()->nutritionist()->create();
        $client = User::factory()->client($nutritionist)->create();
        Subscriber::factory()->active()->create(['nutritionist_id' => $nutritionist->id, 'user_id' => $client->id]);
        $token = $this->bearerFor($client);
        $this->postJson('/api/v1/me/consent', ['type' => 'medical_reports'], $token)->assertSuccessful();

        $response = $this->post('/api/v1/me/reports', [
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('تحليل-سلمى-الخاص.jpg', "\xFF\xD8\xFF broken"),
            'category' => 'lab_test', 'title' => 'عنوان-سري-جدًا',
        ], $token + ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertStringNotContainsString('سلمى', $response->json('message'));
        $this->assertStringNotContainsString('عنوان-سري', $response->json('message'));
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }
}
