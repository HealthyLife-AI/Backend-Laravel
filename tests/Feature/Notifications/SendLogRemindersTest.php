<?php

namespace Tests\Feature\Notifications;

use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * S5-06 / FR-22: the daily reminder job. Uses the same real-credentials
 * fixture pattern as FcmPushServiceTest — see that file for why the key
 * is a static PEM rather than generated at runtime.
 */
class SendLogRemindersTest extends TestCase
{
    use RefreshDatabase;

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

    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->fixturePath = storage_path('framework/testing/fake-firebase-credentials-2.json');
        file_put_contents($this->fixturePath, json_encode([
            'project_id' => 'test-project',
            'client_email' => 'test@test-project.iam.gserviceaccount.com',
            'private_key' => self::FIXTURE_PRIVATE_KEY,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));
        config(['firebase.credentials_path' => $this->fixturePath]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'x']),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->fixturePath);
        parent::tearDown();
    }

    private function makeClient(?string $fcmToken, ?int $lastLoggedDaysAgo): Subscriber
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $client = User::factory()->create(['nutritionist_id' => $nutritionist->id, 'fcm_token' => $fcmToken]);
        $client->assignRole('client');

        return Subscriber::factory()->active()->create([
            'nutritionist_id' => $nutritionist->id,
            'user_id' => $client->id,
            'last_logged_at' => $lastLoggedDaysAgo === null ? null : now()->subDays($lastLoggedDaysAgo),
        ]);
    }

    public function test_it_pushes_to_a_client_who_has_not_logged_today(): void
    {
        $this->makeClient('device-1', lastLoggedDaysAgo: 1);

        $this->artisan('notifications:send-log-reminders')->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com'));
    }

    public function test_it_pushes_to_a_client_who_never_logged(): void
    {
        $this->makeClient('device-1', lastLoggedDaysAgo: null);

        $this->artisan('notifications:send-log-reminders')->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com'));
    }

    public function test_it_skips_a_client_who_already_logged_today(): void
    {
        $this->makeClient('device-1', lastLoggedDaysAgo: 0);

        $this->artisan('notifications:send-log-reminders')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_it_skips_a_client_with_no_registered_device(): void
    {
        $this->makeClient(null, lastLoggedDaysAgo: 1);

        $this->artisan('notifications:send-log-reminders')->assertSuccessful();

        Http::assertNothingSent();
    }

    /** One recipient's send failing must not stop the rest — same principle as EvaluateAlerts. */
    public function test_one_failed_send_does_not_stop_the_others(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'https://fcm.googleapis.com/*' => Http::sequence()
                ->push(['error' => 'invalid token'], 400)
                ->push(['name' => 'x']),
        ]);
        $this->makeClient('bad-device', lastLoggedDaysAgo: 1);
        $this->makeClient('good-device', lastLoggedDaysAgo: 1);

        $this->artisan('notifications:send-log-reminders')->assertSuccessful();

        Http::assertSentCount(3); // 1 token exchange + 2 send attempts
    }

    public function test_it_does_nothing_and_still_succeeds_when_firebase_is_not_configured(): void
    {
        config(['firebase.credentials_path' => null]);
        $this->makeClient('device-1', lastLoggedDaysAgo: 1);

        $this->artisan('notifications:send-log-reminders')->assertSuccessful();

        Http::assertNothingSent();
    }
}
