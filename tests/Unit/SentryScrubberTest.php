<?php

namespace Tests\Unit;

use App\Support\SentryScrubber;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\UserDataBag;

/** Step 4: nothing sensitive leaves in a Sentry event. */
class SentryScrubberTest extends TestCase
{
    public function test_bodies_headers_tokens_and_the_user_are_removed(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'url' => 'https://api.test/api/v1/me/proposals?token=abc',
            'method' => 'POST',
            'query_string' => 'token=abc',
            'data' => ['kind' => 'allergy', 'data' => ['group' => 'sesame'], 'password' => 'x'],
            'cookies' => ['session' => 's'],
            'headers' => ['Authorization' => 'Bearer eyJ', 'Cookie' => 'a=b', 'Accept' => 'application/json', 'X-Refresh-Token' => 'r'],
        ]);
        $event->setExtra(['refresh_token' => 'r', 'nested' => ['password' => 'p', 'ok' => 1]]);
        $event->setUser(UserDataBag::createFromUserIdentifier(7));

        $out = SentryScrubber::beforeSend($event);
        $request = $out->getRequest();

        $this->assertArrayNotHasKey('data', $request);
        $this->assertArrayNotHasKey('cookies', $request);
        $this->assertSame('https://api.test/api/v1/me/proposals', $request['url']);
        $this->assertSame('[filtered]', $request['query_string']);
        $this->assertSame('[filtered]', $request['headers']['Authorization']);
        $this->assertSame('[filtered]', $request['headers']['Cookie']);
        $this->assertSame('[filtered]', $request['headers']['X-Refresh-Token']);
        $this->assertSame('application/json', $request['headers']['Accept']);
        $this->assertSame(['refresh_token' => '[filtered]', 'nested' => ['password' => '[filtered]', 'ok' => 1]], $out->getExtra());
        $this->assertNull($out->getUser());
        $this->assertSame('POST', $request['method']);
    }
}
