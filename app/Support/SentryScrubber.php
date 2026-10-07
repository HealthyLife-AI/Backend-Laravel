<?php

namespace App\Support;

use Sentry\Event;

/**
 * Strips anything sensitive from a Sentry event before it leaves the
 * server: no request bodies at all (health data, passwords, notes), no
 * Authorization / Cookie headers, no tokens in query strings, and no
 * values under sensitive keys in extra context. Only the error, the
 * stack trace and the request method and path remain.
 */
class SentryScrubber
{
    private const SENSITIVE = '/(authorization|cookie|token|password|secret|api[_-]?key|dsn|refresh|session|fcm)/i';

    public static function beforeSend(Event $event): ?Event
    {
        $request = $event->getRequest();

        if ($request !== []) {
            unset($request['data'], $request['cookies'], $request['env']);

            foreach ($request['headers'] ?? [] as $name => $value) {
                if (preg_match(self::SENSITIVE, (string) $name)) {
                    $request['headers'][$name] = '[filtered]';
                }
            }

            if (isset($request['query_string'])) {
                $request['query_string'] = '[filtered]';
            }

            if (isset($request['url'])) {
                $request['url'] = strtok((string) $request['url'], '?');
            }

            $event->setRequest($request);
        }

        $event->setExtra(self::scrub($event->getExtra()));
        $event->setUser(null);

        return $event;
    }

    /** @param  array<mixed>  $data */
    private static function scrub(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE, $key)) {
                $data[$key] = '[filtered]';
            } elseif (is_array($value)) {
                $data[$key] = self::scrub($value);
            }
        }

        return $data;
    }
}
