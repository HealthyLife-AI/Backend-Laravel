<?php

namespace Tests\Feature\Notifications;

use App\Exceptions\Notifications\PushNotificationException;
use App\Services\Notifications\FcmPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * S5-06 / FR-22: the FCM client — never a real network call in this
 * suite (see AiDraftLlmTest's precedent for the same reasoning: an
 * opt-in real-credentials path is deliberately not exercised here).
 */
class FcmPushServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $fixturePath;

    /**
     * A throwaway RSA key, generated once via the `openssl` CLI (not
     * `openssl_pkey_new()` — that call fails outright in this XAMPP/PHP
     * environment: no `openssl.cnf` on the path, a Windows packaging gap
     * unrelated to anything this project's code does). Belongs to
     * nothing real; the signature's validity is never checked by
     * anything in this test, only that the two HTTP calls happen with
     * the right shape.
     */
    private const FIXTURE_PRIVATE_KEY = <<<'PEM'
    -----BEGIN PRIVATE KEY-----
    MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQCnnKNzlsyZgZQp
    J1k3dfN0i47pXUeEHPoz4f+6ncxEp83ovwF55VmXsb/UB+DO7zc2tlxXs0POFqfa
    rPjVofBT4va9Eq9jl8qjbPbagrsDYKaEwJ6B4LdrZRR0elVoYzHXyFlrK55wmjhl
    nFz35SaQTj4FfuAtxOaDB6H9WguTSuKqPEk3gQx7/ubimkHnXZMRycmsd/EcaaVH
    JoHISIGQuXVJY2PPyJhurOGOnioR+emqcVbx5d1OizAeBmcuGhlzR9+OPr1rZQtn
    qLcvD0/UvXRExHiQNEJtFsftQlJkc0k5k7Hk3BIrPhSc5H/d9Oi5cuJG5TfFRaoj
    j5TmAvLjAgMBAAECggEABODiwwUDHH6whMgf6STWOrCrLrCn2bkMMUFlM5XPMbpe
    nGUe0mDyDB/A0RePnAZLaZzCME1H7nIrXYqGTZWCjLaA7vzhvPjYjYwo3TBL6CvC
    fP+sPWSAgjA1ebRr0vd8Jmcu7xcca/OZK5/y9sYlKrMUTA2m0b425V5uARVDW/yY
    VOoRoaPg97JRcfPrRT+cXXmao1L2hyFznFAtnt64lWcZ4AY7m0/PuJhmQih4LYZo
    GqaqijAt8DJmL0XppPgVBcthYkbZs4o1QtYfSU4f9eXQjegCKJPpbTqeOuGBu9+M
    0m2n1xo9zvc5oC+Q/WR5BPBJNMrkGkv+0Ot/NdMQzQKBgQDssEomYx4KbRyA0WBf
    ++mClCJtVT1bMla2kybaOno35dCH6JkkqPrXnJ7y3x+kfvodc6ggIxj6IZowfo5m
    Mvnk1aemo922UdeKpL99U2iz3kZokdBA1cVdJVLm7WwyT17yhtHOfLc3oRVUvDx8
    HHHtxoTMxw7LER+ZIrtMEeZGTQKBgQC1SYtCm9EtdsoWlJDixQTbzZQFDHZCsYIo
    YGp+AqeCFcfxL6ffQS+4T5bGryDM1sMvlivDefO5L3IwUSVX5VqWM2n6jiW2DbAF
    Whxo1xmFnT9bL+RwjapqdkMLrWbI/4BINPD1fXjP6NZ34y4yu+FFyAKH96vlIRyW
    9AmdRM8V7wKBgQCU09D8TEzib3OByKYaFLPjCLSRHQ0koAWIbgT7KdQZ++bg3rAV
    Li/0jaYgv44NCE7LYCMyef9FoQVsQtfViW46puHxVY6fCt1Gb4t9CYqHt1d8f1t8
    uS6OAF8dl+L1y5S/WWjptuAaGa7pBifePqCgy7hLb0ttAspkp0MwdPzf7QKBgEGr
    TmLmhrNtYG8ligZbUBM/OOtLRFuMaZWut2TGGV+p/C+GD81zk5G0Yu296qfI9BN8
    1oWM25itczPFcT2Ru2rFXRKCA28bLjQCHGBt6rTX7WdexeVvq9e81zSXr7AHvbRq
    WQ7UULsfoPD0vntqS4Q3m5MdSItLn0ufQwxRLKLJAoGBAJQ1+ZWRtHcW9lMi2wjD
    LaxUzol1EFaehv8dNU97euePjt/A7kZizq1p3EtLY725I0ZQIE0K7D67gZgMA7R9
    EpBavUQ+FB8/RRClM5EVeUvLxpdCqjFMw4FtZ/HEFqidJmewrsmNUlGHpUzYw0E4
    YfRSL0FA9p73XItEzuG/TtR4
    -----END PRIVATE KEY-----
    PEM;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturePath = storage_path('framework/testing/fake-firebase-credentials.json');
        file_put_contents($this->fixturePath, json_encode([
            'project_id' => 'test-project',
            'client_email' => 'test@test-project.iam.gserviceaccount.com',
            'private_key' => self::FIXTURE_PRIVATE_KEY,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));

        config(['firebase.credentials_path' => $this->fixturePath]);
    }

    protected function tearDown(): void
    {
        @unlink($this->fixturePath);
        parent::tearDown();
    }

    public function test_is_not_configured_when_neither_source_is_set(): void
    {
        config(['firebase.credentials_path' => null, 'firebase.credentials_json' => null]);

        $this->assertFalse(app(FcmPushService::class)->isConfigured());
    }

    public function test_is_configured_when_the_credentials_file_exists(): void
    {
        $this->assertTrue(app(FcmPushService::class)->isConfigured());
    }

    /**
     * S5-06 follow-up: a git-push PaaS deploy (Taqat/Dokku) has no way to
     * receive an uploaded file, and the credentials file is deliberately
     * gitignored — so the JSON has to travel as the env var's own value,
     * the same way OPENAI_API_KEY is a value, not a path to a file
     * holding one.
     */
    public function test_sending_works_from_inline_json_with_no_file_on_disk(): void
    {
        config(['firebase.credentials_path' => null]);
        config(['firebase.credentials_json' => json_encode([
            'project_id' => 'test-project',
            'client_email' => 'test@test-project.iam.gserviceaccount.com',
            'private_key' => self::FIXTURE_PRIVATE_KEY,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ])]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'x']),
        ]);

        $this->assertTrue(app(FcmPushService::class)->isConfigured());
        app(FcmPushService::class)->send('device-token-1', 'Title', 'Body');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com'));
    }

    /** credentials_json wins when both happen to be set. */
    public function test_inline_json_takes_precedence_over_a_file_path(): void
    {
        config(['firebase.credentials_json' => json_encode([
            'project_id' => 'inline-project',
            'client_email' => 'inline@inline-project.iam.gserviceaccount.com',
            'private_key' => self::FIXTURE_PRIVATE_KEY,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ])]);
        // firebase.credentials_path is still the real fixture file from
        // setUp(), pointing at 'test-project' — if that one gets used
        // instead, this assertion below catches it.

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'x']),
        ]);

        app(FcmPushService::class)->send('device-token-1', 'Title', 'Body');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com/v1/projects/inline-project/'));
    }

    /**
     * S5-06's real follow-up incident: setting the raw JSON directly as
     * `credentials_json` on this project's own Taqat deployment broke
     * login/register outright — the value's unescaped `"` characters
     * corrupted Taqat's own env-var storage before the app ever got a
     * chance to log an exception. Base64 output is exactly
     * `[A-Za-z0-9+/=]`, nothing an env-var store could misinterpret.
     */
    public function test_sending_works_from_base64_encoded_json(): void
    {
        config(['firebase.credentials_path' => null, 'firebase.credentials_json' => null]);
        config(['firebase.credentials_json_base64' => base64_encode(json_encode([
            'project_id' => 'base64-project',
            'client_email' => 'test@base64-project.iam.gserviceaccount.com',
            'private_key' => self::FIXTURE_PRIVATE_KEY,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]))]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'x']),
        ]);

        $this->assertTrue(app(FcmPushService::class)->isConfigured());
        app(FcmPushService::class)->send('device-token-1', 'Title', 'Body');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com/v1/projects/base64-project/'));
    }

    /** base64 wins over both other sources — the safest form should never be silently shadowed. */
    public function test_base64_takes_precedence_over_raw_json_and_a_file_path(): void
    {
        config(['firebase.credentials_json' => json_encode(['project_id' => 'raw-json-project'])]);
        // firebase.credentials_path is still the real fixture file from
        // setUp(), pointing at 'test-project'.
        config(['firebase.credentials_json_base64' => base64_encode(json_encode([
            'project_id' => 'base64-project',
            'client_email' => 'test@base64-project.iam.gserviceaccount.com',
            'private_key' => self::FIXTURE_PRIVATE_KEY,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]))]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'x']),
        ]);

        app(FcmPushService::class)->send('device-token-1', 'Title', 'Body');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com/v1/projects/base64-project/'));
    }

    /** Genuinely invalid base64 must fail loudly, not decode into silent garbage. */
    public function test_invalid_base64_throws_rather_than_producing_garbage(): void
    {
        config(['firebase.credentials_json_base64' => 'not valid base64!!! ###']);

        Http::fake();

        $this->expectException(PushNotificationException::class);
        $this->expectExceptionMessage('not valid base64');
        app(FcmPushService::class)->send('device-token-1', 'Title', 'Body');

        Http::assertNothingSent();
    }

    public function test_sending_exchanges_a_token_then_calls_fcm(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'projects/test-project/messages/1']),
        ]);

        app(FcmPushService::class)->send('device-token-1', 'Title', 'Body');

        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer');

        Http::assertSent(fn ($request) => $request->url() === 'https://fcm.googleapis.com/v1/projects/test-project/messages:send'
            && $request->hasHeader('Authorization', 'Bearer fake-token')
            && $request['message']['token'] === 'device-token-1'
            && $request['message']['notification']['title'] === 'Title');
    }

    /** A batch send to many recipients must not re-authenticate per recipient. */
    public function test_the_access_token_is_reused_across_sends(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'x']),
        ]);

        $fcm = app(FcmPushService::class);
        $fcm->send('device-1', 'T', 'B');
        $fcm->send('device-2', 'T', 'B');
        $fcm->send('device-3', 'T', 'B');

        Http::assertSentCount(4); // 1 token exchange + 3 sends
    }

    public function test_throws_when_fcm_returns_an_error(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'https://fcm.googleapis.com/*' => Http::response(['error' => 'invalid registration token'], 400),
        ]);

        $this->expectException(PushNotificationException::class);
        app(FcmPushService::class)->send('bad-token', 'T', 'B');
    }

    public function test_throws_when_the_token_endpoint_is_unreachable(): void
    {
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(null, 500)]);

        $this->expectException(PushNotificationException::class);
        app(FcmPushService::class)->send('device-1', 'T', 'B');
    }

    public function test_sending_when_not_configured_throws_without_any_http_call(): void
    {
        config(['firebase.credentials_path' => null]);
        Http::fake();

        $this->expectException(PushNotificationException::class);
        app(FcmPushService::class)->send('device-1', 'T', 'B');

        Http::assertNothingSent();
    }
}
