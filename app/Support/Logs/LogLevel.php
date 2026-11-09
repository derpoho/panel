<?php

namespace App\Support\Logs;

/**
 * A line's level: from journald's priority when it was set, otherwise
 * read from the line the way people scan for it (a level word, or a
 * structured "level" field). Anything unmarked is info.
 */
final class LogLevel
{
    public static function detect(string $line, ?int $priority = null): string
    {
        // journald gives everything a service prints priority 6 (or 5), so
        // that only says nobody set one: read the line instead. dockerd, for
        // one, writes level=error lines at priority 6.
        if ($priority !== null && $priority >= 0 && $priority <= 7 && $priority !== 5 && $priority !== 6) {
            return match (true) {
                $priority <= 3 => 'error',
                $priority === 4 => 'warn',
                default => 'debug',
            };
        }

        // Only the start of the line: a level word deep in a message ("no
        // error") says nothing.
        $head = substr($line, 0, 160);

        if (preg_match('/"?(?:level|severity|lvl)"?\s*[:=]\s*"?(\w+)/i', $head, $match) === 1) {
            return self::fromWord($match[1]) ?? 'info';
        }

        $words = 'fatal|panic|crit(?:ical)?|emerg(?:ency)?|alert|error|err|severe|warn(?:ing)?|debug|trace|info|notice';

        // Shouted anywhere (ERROR), or marked ([error], <warn>, error:), or
        // the first word.
        $shouted = '/(?<![A-Za-z])('.strtoupper($words).')(?![A-Za-z])/';
        $marked = '/[\[(<]('.$words.')[\])>]|(?:^|\s)('.$words.'):|^('.$words.')\b/i';

        if (preg_match($shouted, $head, $match) === 1 || preg_match($marked, $head, $match) === 1) {
            return self::fromWord((string) end($match)) ?? 'info';
        }

        if (preg_match('/^(?:Traceback|Exception|Uncaught|PHP (?:Fatal|Parse) error)|^\s+at\s+\S+\(/', $line) === 1) {
            return 'error';
        }

        return 'info';
    }

    private static function fromWord(string $word): ?string
    {
        // pino and bunyan write numbers: 10 trace ... 60 fatal.
        if (ctype_digit($word)) {
            $number = (int) $word;

            return match (true) {
                $number >= 50 => 'error',
                $number >= 40 => 'warn',
                $number >= 30 => 'info',
                $number >= 10 => 'debug',
                default => null,
            };
        }

        return match (strtolower($word)) {
            'fatal', 'panic', 'crit', 'critical', 'emerg', 'emergency', 'alert', 'error', 'err', 'severe', 'e' => 'error',
            'warn', 'warning', 'w' => 'warn',
            'debug', 'trace', 'd' => 'debug',
            'info', 'notice', 'i' => 'info',
            default => null,
        };
    }
}
