<?php

namespace Tests\Concerns;

use App\Services\Notifications\FcmPushService;

/**
 * Swaps FCM for a recorder: every push is kept in $this->pushes (token,
 * title, body, data) instead of going to Google.
 */
trait FakesPush
{
    /** @var list<array{token: string, title: string, body: string, data: array<string, string>}> */
    protected array $pushes = [];

    protected function fakePush(bool $configured = true): void
    {
        $test = $this;

        $this->app->instance(FcmPushService::class, new class($test, $configured) extends FcmPushService
        {
            public function __construct(private $test, private bool $configured) {}

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function send(string $deviceToken, string $title, string $body, array $data = []): void
            {
                $this->test->recordPush(['token' => $deviceToken, 'title' => $title, 'body' => $body, 'data' => $data]);
            }
        });
    }

    public function recordPush(array $push): void
    {
        $this->pushes[] = $push;
    }
}
