<?php

namespace App\Support\Logs;

use Illuminate\Validation\ValidationException;

/**
 * A machine's log skip rules: services whose lines never leave it (names,
 * `*` for any run of characters), and line patterns, each for every
 * service or the ones its own service rule matches. The daemon applies
 * them before anything is sent; this is the panel's side of the shape.
 *
 * @phpstan-type Rules array{services: list<string>, patterns: list<array{service: string|null, pattern: string}>}
 */
final class LogSkipRules
{
    /** The daemon's limits: rules of each kind, and characters a rule. */
    public const MAX_RULES = 100;

    public const MAX_LENGTH = 300;

    /** @return Rules */
    public static function none(): array
    {
        return ['services' => [], 'patterns' => []];
    }

    /**
     * Rules as someone typed them, tidied: blanks dropped, repeats once.
     * Throws when a pattern isn't one the daemon can use.
     *
     * @param  array<mixed>  $input
     * @return Rules
     */
    public static function fromInput(array $input): array
    {
        $services = array_values(array_unique(array_filter(
            array_map(fn ($s) => trim((string) $s), (array) ($input['services'] ?? [])),
            fn (string $s) => $s !== '',
        )));

        $patterns = [];
        $errors = [];
        foreach (array_values((array) ($input['patterns'] ?? [])) as $i => $rule) {
            $pattern = (string) (is_array($rule) ? ($rule['pattern'] ?? '') : '');
            $service = trim((string) (is_array($rule) ? ($rule['service'] ?? '') : ''));
            if (trim($pattern) === '') {
                continue;
            }
            if (($problem = self::problem($pattern)) !== null) {
                $errors["patterns.{$i}.pattern"] = $problem;
            }
            $patterns[] = ['service' => $service === '' ? null : $service, 'pattern' => $pattern];
        }

        if (count($services) > self::MAX_RULES) {
            $errors['services'] = 'Up to '.self::MAX_RULES.' services.';
        }
        if (count($patterns) > self::MAX_RULES) {
            $errors['patterns'] = 'Up to '.self::MAX_RULES.' patterns.';
        }
        foreach ($services as $service) {
            if (mb_strlen($service) > self::MAX_LENGTH) {
                $errors['services'] = 'Keep each service under '.self::MAX_LENGTH.' characters.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['services' => $services, 'patterns' => $patterns];
    }

    /**
     * The rules a daemon reported, kept to the shape the panel expects.
     *
     * @param  array<mixed>|null  $reported
     * @return Rules
     */
    public static function fromDaemon(?array $reported): array
    {
        $services = array_values(array_filter((array) ($reported['services'] ?? []), 'is_string'));
        $patterns = [];
        foreach ((array) ($reported['patterns'] ?? []) as $rule) {
            if (is_array($rule) && is_string($rule['pattern'] ?? null)) {
                $patterns[] = ['service' => is_string($rule['service'] ?? null) ? $rule['service'] : null, 'pattern' => $rule['pattern']];
            }
        }

        return ['services' => $services, 'patterns' => $patterns];
    }

    /** @param  Rules  $rules */
    public static function isEmpty(array $rules): bool
    {
        return $rules['services'] === [] && $rules['patterns'] === [];
    }

    /**
     * What saving these rules added: service rules and line patterns that
     * weren't there before. Service rules compare as the daemon reads them
     * (lowercase), so "Wings" after "wings" isn't new.
     *
     * @param  Rules  $before
     * @param  Rules  $after
     * @return array{services: list<string>, patterns: list<array{service: string|null, pattern: string}>}
     */
    public static function added(array $before, array $after): array
    {
        $had = array_map(fn (string $s) => mb_strtolower($s), $before['services']);
        $key = fn (array $rule) => mb_strtolower((string) $rule['service'])."\0".$rule['pattern'];
        $hadPatterns = array_map($key, $before['patterns']);

        return [
            'services' => array_values(array_filter($after['services'], fn (string $s) => ! in_array(mb_strtolower($s), $had, true))),
            'patterns' => array_values(array_filter($after['patterns'], fn (array $rule) => ! in_array($key($rule), $hadPatterns, true))),
        ];
    }

    /**
     * Whether a service rule matches a service's name, the way the daemon
     * decides (daemon-core's logskip.rs): case doesn't matter, a leading
     * slash is ignored, `*` is any run of characters, and a systemd unit
     * matches with or without its ".service".
     */
    public static function matchesService(string $rule, string $name): bool
    {
        $rule = mb_strtolower(trim($rule));
        $name = mb_strtolower(ltrim($name, '/'));

        return self::glob($rule, $name)
            || (str_ends_with($name, '.service') && self::glob($rule, substr($name, 0, -strlen('.service'))));
    }

    /** `*` matches any run of characters; everything else matches itself. */
    private static function glob(string $pattern, string $text): bool
    {
        $parts = explode('*', $pattern);
        if (count($parts) === 1) {
            return $pattern === $text;
        }
        $first = $parts[0];
        $last = $parts[count($parts) - 1];
        if (! str_starts_with($text, $first) || ! str_ends_with(substr($text, strlen($first)), $last)) {
            return false;
        }
        $rest = substr($text, strlen($first), strlen($text) - strlen($first) - strlen($last));
        foreach (array_slice($parts, 1, -1) as $part) {
            $at = $part === '' ? 0 : strpos($rest, $part);
            if ($at === false) {
                return false;
            }
            $rest = substr($rest, $at + strlen($part));
        }

        return true;
    }

    /**
     * Why the daemon couldn't use a pattern, or null if it can. It runs
     * Rust's regex, which has no look-around or backreferences, so those
     * are turned down here rather than ignored on the machine.
     */
    public static function problem(string $pattern): ?string
    {
        if (mb_strlen($pattern) > self::MAX_LENGTH) {
            return 'Keep patterns under '.self::MAX_LENGTH.' characters.';
        }
        if (preg_match('/\(\?<?[=!]|\(\?>|\\\\[1-9]|\\\\k[<{\']/', $pattern) === 1) {
            return 'Look-ahead, look-behind and backreferences aren’t supported.';
        }
        if (@preg_match("\x01".$pattern."\x01u", '') === false) {
            return 'That isn’t a valid regular expression.';
        }

        return null;
    }
}
