<?php

namespace App\Mcp\Tools\Monitor;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Models\Monitor;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Turns an agent's arguments for a new or changed monitor into what the
 * monitor dialog sends, so MonitorSettings checks them exactly as it
 * checks the dashboard's: the machine by id or name, the type taken from
 * what's given, and (for a change) everything not given kept as it is.
 */
final class MonitorInput
{
    /**
     * The fields create_monitor and update_monitor take.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function properties(): array
    {
        return [
            'name' => Schema::string('What to call it, like “Shop” (up to 80 characters).', maxLength: 80),
            'type' => Schema::string('http checks a URL; tcp checks that a port accepts connections. Taken from what you give when left out: url means http, host and port mean tcp.', Monitor::TYPES),
            'url' => Schema::string('For http: the full address, starting with http:// or https://. It must be reachable from the internet: private and internal addresses are refused.', maxLength: 2048),
            'host' => Schema::string('For tcp: a public hostname or IP address (private and internal addresses are refused).', maxLength: 255),
            'port' => Schema::integer('For tcp: the port, 1–65535.', 1, 65535),
            'interval' => Schema::integer('Seconds between checks: 30, 60, 180, 300, 600 or 1800, no shorter than the plan allows (list_monitors shows plan.minIntervalSeconds).') + ['enum' => Monitor::INTERVALS],
            'timeout' => Schema::integer('Seconds to wait for an answer before it counts as failed, 2–30 (never more than the interval).', 2, 30),
            'expected_status' => Schema::integer('For http: the exact status code that counts as up, like 200. Left out (or 0) any 2xx or 3xx counts; redirects aren’t followed.', 0, 599),
            'keyword' => Schema::string('For http: text the page must contain (any case) to count as up. An empty string clears it.', maxLength: 200),
            'machine' => Schema::machine('The machine it belongs to, by id or name, so it shows on that machine and its alerts hold off while the machine is in maintenance. An empty string or “none” unlinks it.'),
            'alerts' => Schema::boolean('Send alerts when it goes down and comes back (default true). Where they go is the workspace’s alert settings.'),
        ];
    }

    /**
     * What the dialog would send: the arguments given, over the monitor's
     * current settings or the dialog's defaults for a new one.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public static function from(McpContext $context, array $arguments, ?Monitor $current = null): array
    {
        Validator::make($arguments, [
            'type' => ['nullable', Rule::in(Monitor::TYPES)],
            'interval' => ['nullable', 'integer', Rule::in(Monitor::INTERVALS)],
            'expected_status' => ['nullable', 'integer'],
            'machine' => ['nullable', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) && ! is_int($value)) {
                    $fail('The machine must be an id or a name.');
                }
            }],
        ], [
            'type.in' => 'type must be http or tcp.',
            'interval.in' => 'interval must be one of '.implode(', ', Monitor::INTERVALS).' seconds.',
        ])->validate();

        $given = fn (string $key) => array_key_exists($key, $arguments);
        $input = $current !== null ? [
            'name' => $current->name,
            'type' => $current->type,
            'url' => $current->url,
            'host' => $current->host,
            'port' => $current->port,
            'interval' => $current->interval_seconds,
            'timeout' => $current->timeout_seconds,
            'expected_status' => $current->expected_status,
            'keyword' => $current->keyword,
            'machine_id' => $current->machine_id,
            'alerts' => $current->alerts,
        ] : [
            'interval' => max(60, $context->workspace->minMonitorInterval()),
            'timeout' => 10,
            'machine_id' => null,
            'alerts' => true,
        ];

        foreach (['name', 'url', 'host', 'port', 'interval', 'timeout', 'keyword', 'alerts'] as $key) {
            if ($given($key)) {
                $input[$key] = $arguments[$key];
            }
        }

        if ($given('expected_status')) {
            $input['expected_status'] = in_array($arguments['expected_status'], [null, 0, '0', ''], true) ? null : $arguments['expected_status'];
        }

        if ($given('machine')) {
            $machine = trim((string) $arguments['machine']);
            $input['machine_id'] = $machine === '' || mb_strtolower($machine) === 'none' ? null : $context->machine($machine)->id;
        }

        // The type follows what was given, unless it was said.
        if ($given('type') && $arguments['type'] !== null) {
            $input['type'] = $arguments['type'];
        } elseif ($given('url') && ! $given('host') && ! $given('port')) {
            $input['type'] = 'http';
        } elseif (($given('host') || $given('port')) && ! $given('url')) {
            $input['type'] = 'tcp';
        }

        // A new monitor is named after what it checks, as the dialog does.
        if ($current === null && blank($input['name'] ?? null)) {
            $suggested = self::suggestName($input);
            if ($suggested !== null) {
                $input['name'] = $suggested;
            }
        }

        return $input;
    }

    /**
     * The dialog's name for a monitor nobody named: the URL's hostname
     * without www., or host:port.
     *
     * @param  array<string, mixed>  $input
     */
    private static function suggestName(array $input): ?string
    {
        if (($input['type'] ?? null) === 'tcp') {
            return is_string($input['host'] ?? null) && trim($input['host']) !== '' && is_numeric($input['port'] ?? null)
                ? mb_substr(trim($input['host']).':'.(int) $input['port'], 0, 80)
                : null;
        }

        $host = is_string($input['url'] ?? null) ? parse_url(trim($input['url']), PHP_URL_HOST) : null;

        return is_string($host) && $host !== '' ? mb_substr((string) preg_replace('/^www\./', '', $host), 0, 80) : null;
    }
}
