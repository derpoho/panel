<?php

namespace App\Support\Sentry;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Tracing\SamplingContext;

/**
 * What leaves for Sentry: the error and where it happened, not who or what
 * it happened to. Request bodies, query strings, headers and cookies are
 * dropped; tokens, keys, passwords, email addresses and the values in SQL
 * errors are masked wherever they turn up (messages, exception values,
 * breadcrumbs, extra).
 */
final class Scrub
{
    private const string MASK = '[filtered]';

    /** @var list<array{0: string, 1: string}> pattern, replacement */
    private const array PATTERNS = [
        ['/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer '.self::MASK],
        // key=value and "key": "value" pairs for anything secret-shaped.
        ['/((?:pass(?:word)?|passwd|secret|token|api[_-]?key|auth(?:orization)?|cookie|session|dsn|private[_-]?key|signature)["\']?\s*[:=]\s*["\']?)[^\s"\'&,;)}]+/i', '$1'.self::MASK],
        // Our own and common provider token shapes.
        ['/\b(?:sos[a-z]?_|sosd_|sk_(?:live|test)_|whsec_|re_|phc_|sntry[su]_|gh[pousr]_|xox[abp]-)[A-Za-z0-9_-]{8,}/', self::MASK],
        ['/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{5,}/', self::MASK],
        ['/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s', self::MASK],
        ['/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[email]'],
        // Long random-looking strings (hex or base64 secrets).
        ['/\b[a-f0-9]{40,}\b/i', self::MASK],
    ];

    public static function beforeSend(Event $event, ?EventHint $hint = null): Event
    {
        $request = $event->getRequest();
        $event->setRequest(array_filter([
            'method' => $request['method'] ?? null,
            'url' => isset($request['url']) ? strtok((string) $request['url'], '?') : null,
        ]));
        $event->setUser(null);

        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(self::text($exception->getValue()));
        }

        if ($event->getMessage() !== null) {
            $event->setMessage(self::text($event->getMessage()), [], $event->getMessageFormatted() === null ? null : self::text($event->getMessageFormatted()));
        }

        $event->setBreadcrumb(array_map(function (Breadcrumb $crumb): Breadcrumb {
            $clean = new Breadcrumb(
                $crumb->getLevel(),
                $crumb->getType(),
                $crumb->getCategory(),
                $crumb->getMessage() === null ? null : self::text($crumb->getMessage()),
                self::values($crumb->getMetadata()),
                $crumb->getTimestamp(),
            );

            return $clean;
        }, $event->getBreadcrumbs()));

        $event->setExtra(self::values($event->getExtra()));

        return $event;
    }

    /**
     * Every assistant answer is traced, so Agent Tracing sees each one;
     * everything else at SENTRY_TRACES_SAMPLE_RATE.
     */
    public static function tracesSampler(SamplingContext $context): float
    {
        if ($context->getParentSampled() !== null) {
            return $context->getParentSampled() ? 1.0 : 0.0;
        }

        if (in_array($context->getTransactionContext()?->getName(), ['/assistant', '/ask'], true)) {
            return 1.0;
        }

        return (float) config('sentry.traces_sample_rate', 0.0);
    }

    /** Masks secrets in a string, and the values in a database error's SQL. */
    public static function text(string $text): string
    {
        // QueryException: "... (Connection: pgsql, SQL: select ... where email = x)".
        $text = (string) preg_replace_callback('/(SQL: )(.*)\)$/s', fn (array $m) => $m[1].self::sqlValues($m[2]).')', $text);

        foreach (self::PATTERNS as [$pattern, $replacement]) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }

    /**
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    private static function values(array $values): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match('/pass|secret|token|key|auth|cookie|session|dsn|signature|bindings/i', $key) === 1) {
                $clean[$key] = self::MASK;
            } elseif (is_string($value)) {
                $clean[$key] = self::text($value);
            } elseif (is_array($value)) {
                $clean[$key] = self::values($value);
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /** Quoted strings and numbers in SQL become ?, so the shape stays and the data doesn't. */
    private static function sqlValues(string $sql): string
    {
        $sql = (string) preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/", '?', $sql);

        return (string) preg_replace('/(?<![\w$."])\b\d+(?:\.\d+)?\b/', '?', $sql);
    }
}
