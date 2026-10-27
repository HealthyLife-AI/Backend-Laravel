<?php

namespace Tests\Unit;

use App\Support\SentryScrubber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\UserDataBag;

/** Nothing sensitive leaves in a Sentry event: requests, users, SQL bindings, and what is typed in a message. */
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
            'files' => ['file' => ['name' => 'lab.pdf']],
            'env' => ['REMOTE_ADDR' => '10.1.2.3'],
            'headers' => ['Authorization' => 'Bearer eyJ', 'Cookie' => 'a=b', 'Accept' => 'application/json', 'X-Refresh-Token' => 'r', 'X-Forwarded-For' => '1.2.3.4', 'X-Real-IP' => '1.2.3.4'],
        ]);
        $event->setExtra(['refresh_token' => 'r', 'nested' => ['password' => 'p', 'ok' => 1]]);
        $event->setUser(UserDataBag::createFromUserIdentifier(7));

        $out = SentryScrubber::beforeSend($event);
        $request = $out->getRequest();

        foreach (['data', 'cookies', 'files', 'env'] as $dropped) {
            $this->assertArrayNotHasKey($dropped, $request);
        }
        $this->assertSame('https://api.test/api/v1/me/proposals', $request['url']);
        $this->assertSame('[filtered]', $request['query_string']);
        foreach (['Authorization', 'Cookie', 'X-Refresh-Token', 'X-Forwarded-For', 'X-Real-IP'] as $header) {
            $this->assertSame('[filtered]', $request['headers'][$header], $header);
        }
        $this->assertSame('application/json', $request['headers']['Accept']);
        $this->assertSame(['refresh_token' => '[filtered]', 'nested' => ['password' => '[filtered]', 'ok' => 1]], $out->getExtra());
        $this->assertNull($out->getUser());
        $this->assertSame('POST', $request['method']);
    }

    // ---- text ----------------------------------------------------------------------------------

    /** @return array<string, array{string, string}> input => text that must not survive */
    public static function leaks(): array
    {
        return [
            'an e-mail' => ['Could not notify salma.user+tag@example.co.uk now', 'salma.user'],
            'a plain phone' => ['Bad phone 0599123456 for the patient', '0599123456'],
            'a phone with a plus' => ['Phone +970 59 912 3456 rejected', '912 3456'],
            'a phone with dashes' => ['Phone 059-912-3456 rejected', '912-3456'],
            'a phone with dots and brackets' => ['Phone (059) 912.3456 rejected', '912.3456'],
            'an Arabic-Indic phone' => ['رقم ٠٥٩٩١٢٣٤٥٦ غير صالح', '٠٥٩٩١٢٣٤٥٦'],
            'a Persian-digit phone' => ['Phone ۰۵۹۹۱۲۳۴۵۶ rejected', '۰۵۹۹۱۲۳۴۵۶'],
            'Arabic free text' => ['Failed for note: أعاني من حساسية القمح وأتناول دواء الضغط', 'حساسية'],
            'an id_token in a URL' => ['cURL error 6 for https://oauth2.googleapis.com/tokeninfo?id_token=SECRET123&x=1', 'SECRET123'],
            'a bearer token' => ['Rejected header Bearer abc.def-ghi_123', 'abc.def-ghi_123'],
            'a JWT' => ['Token eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjMifQ.sig_nature rejected', 'eyJhbGciOiJIUzI1NiJ9'],
            'a device token' => ['Invalid registration token fGhIjKlMnOpQrStUvWxYz0123456789abcdefghijklm:APA91bExample', 'APA91bExample'],
            'the SQL of a query error' => ["SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint failed: patient_wellbeing_logs.mood (Connection: mysql, SQL: insert into `patient_wellbeing_logs` (`note`) values (أعاني من الأرق وأقلق على ابني سلمى))", 'insert into'],
            'a duplicate-entry value' => ["SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'some.username' for key 'users_username_unique'", 'some.username'],
            'an incorrect value' => ["SQLSTATE[HY000]: General error: 1366 Incorrect string value: 'typed text here' for column 'note' at row 1", 'typed text here'],
        ];
    }

    #[DataProvider('leaks')]
    public function test_it_is_removed_from_a_message(string $message, string $secret): void
    {
        $cleaned = SentryScrubber::text($message);

        $this->assertStringNotContainsString($secret, $cleaned);
    }

    public function test_the_useful_part_of_a_message_stays(): void
    {
        $this->assertSame('Call to undefined method App\Services\Foo::bar()', SentryScrubber::text('Call to undefined method App\Services\Foo::bar()'));
        $this->assertSame('AI provider returned HTTP 429. Rate limit reached', SentryScrubber::text('AI provider returned HTTP 429. Rate limit reached'));
        $this->assertSame('FCM returned HTTP 404 (UNREGISTERED)', SentryScrubber::text('FCM returned HTTP 404 (UNREGISTERED)'));
        $this->assertSame('Reports for 2026-10-26 failed on subscriber 42', SentryScrubber::text('Reports for 2026-10-26 failed on subscriber 42'), 'a date and a short id are not a phone');
        $this->assertSame('https://api.test/up', SentryScrubber::text('https://api.test/up?x=1'));
    }

    public function test_a_query_error_keeps_its_driver_part_and_loses_the_sql(): void
    {
        $out = SentryScrubber::text("SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint failed: x (Connection: sqlite, SQL: insert into \"x\" (\"a\") values (secret))");

        $this->assertStringContainsString('SQLSTATE[23000]', $out);
        $this->assertStringContainsString('(SQL removed)', $out);
        $this->assertStringNotContainsString('secret', $out);
    }

    public function test_a_very_long_message_is_cut(): void
    {
        $this->assertLessThanOrEqual(501, mb_strlen(SentryScrubber::text(str_repeat('word ', 400))));
    }

    // ---- events --------------------------------------------------------------------------------

    public function test_exception_messages_the_event_message_and_extra_are_cleaned(): void
    {
        $event = Event::createEvent();
        $event->setExceptions([new ExceptionDataBag(new \RuntimeException('Notify 0599123456 / a@b.co failed: عانيت من الصداع'))]);
        $event->setMessage('Also 0599123456');
        $event->setExtra(['reason' => 'mail a@b.co', 'nested' => ['phone' => 'call 0599123456']]);

        $out = SentryScrubber::beforeSend($event);
        $everything = json_encode([$out->getExceptions()[0]->getValue(), $out->getMessage(), $out->getExtra()], JSON_UNESCAPED_UNICODE);

        foreach (['0599123456', 'a@b.co', 'الصداع'] as $secret) {
            $this->assertStringNotContainsString($secret, $everything);
        }
        $this->assertStringContainsString('[phone]', $everything);
        $this->assertStringContainsString('[email]', $everything);
        $this->assertStringContainsString('[ar-text]', $everything);
    }

    // ---- breadcrumbs ---------------------------------------------------------------------------

    public function test_a_breadcrumb_with_a_request_body_is_dropped(): void
    {
        foreach (['request_body', 'body', 'payload', 'json', 'form_params'] as $key) {
            $crumb = new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_HTTP, 'http', 'POST https://llm.test/chat', [$key => '{"messages":[...]}']);

            $this->assertNull(SentryScrubber::beforeBreadcrumb($crumb), $key);
        }
    }

    public function test_sql_bindings_are_removed_but_the_sql_text_stays(): void
    {
        $crumb = new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'sql.query', 'select * from `users` where `phone` = ?', ['bindings' => ['0599123456'], 'connectionName' => 'mysql', 'executionTimeMs' => 1.2]);

        $out = SentryScrubber::beforeBreadcrumb($crumb);

        $this->assertSame('select * from `users` where `phone` = ?', $out->getMessage());
        $this->assertArrayNotHasKey('bindings', $out->getMetadata());
        $this->assertSame('mysql', $out->getMetadata()['connectionName']);
        $this->assertStringNotContainsString('0599123456', json_encode($out->getMetadata()));
    }

    public function test_a_breadcrumb_message_and_data_are_cleaned(): void
    {
        $crumb = new Breadcrumb(Breadcrumb::LEVEL_WARNING, Breadcrumb::TYPE_DEFAULT, 'log', 'Notify a@b.co 0599123456 أعاني من السكر', ['context' => ['reason' => 'call 0599123456', 'token' => 'abc']]);

        $out = SentryScrubber::beforeBreadcrumb($crumb);
        $everything = json_encode([$out->getMessage(), $out->getMetadata()], JSON_UNESCAPED_UNICODE);

        foreach (['a@b.co', '0599123456', 'السكر', 'abc'] as $secret) {
            $this->assertStringNotContainsString($secret, $everything);
        }
        $this->assertSame('log', $out->getCategory());
    }

    public function test_breadcrumbs_already_on_an_event_get_the_same_treatment(): void
    {
        $event = Event::createEvent();
        $event->setBreadcrumb([
            new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_HTTP, 'http', 'POST x', ['request_body' => 'secret body']),
            new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'sql.query', 'select ?', ['bindings' => ['x@y.co']]),
        ]);

        $crumbs = SentryScrubber::beforeSend($event)->getBreadcrumbs();

        $this->assertCount(1, $crumbs);
        $this->assertSame('select ?', $crumbs[0]->getMessage());
        $this->assertArrayNotHasKey('bindings', $crumbs[0]->getMetadata());
    }
}
