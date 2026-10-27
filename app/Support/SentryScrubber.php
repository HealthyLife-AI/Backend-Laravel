<?php

namespace App\Support;

use Sentry\Breadcrumb;
use Sentry\Event;

/**
 * Strips anything sensitive from a Sentry event before it leaves the server.
 *
 * What never leaves: request bodies and uploaded files, Authorization / Cookie /
 * forwarding headers, query strings, the user (and with it the IP), SQL bindings.
 *
 * What is cleaned in every text that does leave (exception messages, the event
 * message, breadcrumb messages and data, extra context, span descriptions):
 *   - e-mail addresses and phone numbers (7+ digits, with + / spaces / dashes /
 *     dots / brackets, Latin or Arabic-Indic digits);
 *   - Arabic-script text, which in an error message can only be something a user
 *     typed (the app's own messages are English);
 *   - URL query strings (a Google id_token or an API key in a failed-request message);
 *   - bearer tokens, JWTs and any long opaque token;
 *   - the SQL of a QueryException (Laravel writes the bindings into its message),
 *     and the offending value of a "Duplicate entry" / "Incorrect value" error.
 * Only the error class, its cleaned message, the stack trace, and the request
 * method and path remain.
 */
class SentryScrubber
{
    private const SENSITIVE = '/(authorization|cookie|token|password|secret|api[_-]?key|dsn|refresh|session|fcm|forwarded|real-ip|client-ip|csrf|xsrf)/i';

    /** Longest message kept, after cleaning. */
    private const MAX_MESSAGE = 500;

    public static function beforeSend(Event $event): ?Event
    {
        self::scrubRequest($event);

        $event->setExtra(self::scrub($event->getExtra()));
        $event->setUser(null);

        foreach ($event->getExceptions() as $bag) {
            $bag->setValue(self::text($bag->getValue()));
        }

        if ($event->getMessage() !== null) {
            $event->setMessage(self::text($event->getMessage()), [], self::text((string) $event->getMessageFormatted()));
        }

        $event->setBreadcrumb(array_values(array_filter(array_map(fn (Breadcrumb $b) => self::beforeBreadcrumb($b), $event->getBreadcrumbs()))));

        return $event;
    }

    /** Transactions (performance data) get the same request/user treatment and no span data. */
    public static function beforeSendTransaction(Event $event): ?Event
    {
        self::scrubRequest($event);
        $event->setUser(null);
        $event->setExtra(self::scrub($event->getExtra()));

        foreach ($event->getSpans() as $span) {
            $span->setData([]);
            $span->setDescription($span->getDescription() === null ? null : self::text($span->getDescription()));
        }

        return $event;
    }

    /**
     * A breadcrumb that carries a request body is dropped; one that carries SQL
     * bindings keeps its SQL text and loses the bindings; every other one keeps its
     * category and has its message and data cleaned.
     */
    public static function beforeBreadcrumb(Breadcrumb $crumb): ?Breadcrumb
    {
        $metadata = $crumb->getMetadata();

        foreach (['request_body', 'body', 'content', 'payload', 'request', 'form_params', 'json'] as $key) {
            if (! empty($metadata[$key])) {
                return null;
            }
        }

        foreach (['bindings', 'sql_bindings', 'parameters', 'params'] as $key) {
            if (array_key_exists($key, $metadata)) {
                $crumb = $crumb->withoutMetadata($key);
            }
        }

        if ($crumb->getMessage() !== null) {
            $crumb = $crumb->withMessage(self::text($crumb->getMessage()));
        }

        foreach (self::scrub($crumb->getMetadata(), true) as $name => $value) {
            $crumb = $crumb->withMetadata((string) $name, $value);
        }

        return $crumb;
    }

    /** One cleaned string: see the class docblock for what goes. */
    public static function text(string $text): string
    {
        // A QueryException: "<driver message> (Connection: x, SQL: <statement with its values>)".
        $text = preg_replace('/\(Connection: .*?SQL: .*\)\s*$/s', '(SQL removed)', $text) ?? $text;
        $text = preg_replace('/(Duplicate entry )\'.*?\'( for key)/s', '$1\'[value]\'$2', $text) ?? $text;
        $text = preg_replace('/(Incorrect \w+ value: )\'.*?\'( for column)/s', '$1\'[value]\'$2', $text) ?? $text;
        $text = preg_replace('/(UNIQUE constraint failed: )[\w.,\s]+/', '$1[columns]', $text) ?? $text;

        // Anything after a "?" in a URL.
        $text = preg_replace('~(https?://[^\s?"\'<>]+)\?[^\s"\'<>]*~i', '$1', $text) ?? $text;

        $text = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer [token]', $text) ?? $text;
        $text = preg_replace('/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]+\.?[A-Za-z0-9_-]*/', '[token]', $text) ?? $text;
        $text = preg_replace('/[A-Za-z0-9_:-]{40,}/', '[token]', $text) ?? $text;

        $text = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+/', '[email]', $text) ?? $text;

        // 7 or more digits (Latin, Arabic-Indic, Persian), optionally separated; an ISO date is not a phone.
        $text = preg_replace_callback(
            '/\+?[0-9٠-٩۰-۹](?:[\s\-.()]*[0-9٠-٩۰-۹]){6,}/u',
            fn (array $m) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $m[0]) === 1 ? $m[0] : '[phone]',
            $text,
        ) ?? $text;

        // Arabic script: a user typed it.
        $text = preg_replace('/\p{Arabic}[\p{Arabic}\p{Mn}\s،؟؛.!:\-\d]*/u', '[ar-text]', $text) ?? $text;

        return mb_strlen($text) > self::MAX_MESSAGE ? mb_substr($text, 0, self::MAX_MESSAGE).'…' : $text;
    }

    private static function scrubRequest(Event $event): void
    {
        $request = $event->getRequest();

        if ($request === []) {
            return;
        }

        // No body, no files, no cookies, no server environment (REMOTE_ADDR).
        unset($request['data'], $request['cookies'], $request['env'], $request['files']);

        foreach ($request['headers'] ?? [] as $name => $value) {
            if (preg_match(self::SENSITIVE, (string) $name)) {
                $request['headers'][$name] = '[filtered]';
            }
        }

        if (isset($request['query_string'])) {
            $request['query_string'] = '[filtered]';
        }

        if (isset($request['url'])) {
            $request['url'] = self::text((string) strtok((string) $request['url'], '?'));
        }

        $event->setRequest($request);
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function scrub(array $data, bool $texts = true): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE, $key)) {
                $data[$key] = '[filtered]';
            } elseif (is_array($value)) {
                $data[$key] = self::scrub($value, $texts);
            } elseif (is_string($value)) {
                $data[$key] = self::text($value);
            }
        }

        return $data;
    }
}
