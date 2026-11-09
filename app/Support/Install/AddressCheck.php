<?php

namespace App\Support\Install;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Whether the panel's address points at this server, as far as it can
 * tell from here: the name and a made-up subdomain of it (workspaces open
 * on subdomains) are looked up, and compared with this server's own
 * addresses. Only advice: DNS may still be on its way.
 */
class AddressCheck
{
    /** @var Closure(string): list<string> */
    private Closure $resolver;

    /** @param  (Closure(string): list<string>)|null  $resolver */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? self::lookUp(...);
    }

    /**
     * @return array{status: 'ok'|'warn'|'fail', lines: list<array{status: 'ok'|'warn'|'fail', text: string}>}
     */
    public function check(string $host, Request $request): array
    {
        $host = strtolower($host);

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return self::result([['ok', "{$host} and its subdomains always point at the computer you’re on, so this works for trying it out."]]);
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::result([['warn', 'That’s an IP address. It works for the panel, but workspaces open on subdomains, so they need a domain.']]);
        }

        $found = ($this->resolver)($host);
        if ($found === []) {
            return self::result([['fail', "{$host} doesn’t point anywhere yet. Add an A record for it with this server’s address (it can take a few minutes to show up)."]]);
        }

        $ours = $this->serverAddresses($request);
        $lines = [];
        if ($request->getHost() === $host || array_intersect($found, $ours) !== []) {
            $lines[] = ['ok', "{$host} points at this server."];
        } else {
            $lines[] = ['warn', "{$host} points at ".implode(', ', $found).'. Make sure that’s this server.'];
        }

        $wildcard = ($this->resolver)('serveros-check-'.Str::lower(Str::random(6)).'.'.$host);
        $lines[] = $wildcard === []
            ? ['warn', "*.{$host} doesn’t point anywhere yet. Workspaces open on subdomains (acme.{$host}), so add an A record for *.{$host} too."]
            : (array_intersect($wildcard, $found) !== [] || array_intersect($wildcard, $ours) !== []
                ? ['ok', "*.{$host} does too, so workspaces will open."]
                : ['warn', "*.{$host} points at ".implode(', ', $wildcard).', not where '.$host.' does.']);

        return self::result($lines);
    }

    /**
     * This server's own addresses, as far as it can see them.
     *
     * @return list<string>
     */
    private function serverAddresses(Request $request): array
    {
        $server = $request->server->get('SERVER_ADDR');
        $addresses = [is_string($server) ? $server : '', $request->getHost()];

        return array_values(array_filter($addresses, fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP) !== false));
    }

    /** @return list<string> */
    private static function lookUp(string $host): array
    {
        $v4 = @gethostbynamel($host) ?: [];
        $v6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

        return array_values(array_unique([...$v4, ...$v6]));
    }

    /**
     * @param  list<array{0: 'ok'|'warn'|'fail', 1: string}>  $lines
     * @return array{status: 'ok'|'warn'|'fail', lines: list<array{status: 'ok'|'warn'|'fail', text: string}>}
     */
    private static function result(array $lines): array
    {
        $statuses = array_column($lines, 0);
        $status = in_array('fail', $statuses, true) ? 'fail' : (in_array('warn', $statuses, true) ? 'warn' : 'ok');

        return ['status' => $status, 'lines' => array_map(fn (array $line) => ['status' => $line[0], 'text' => $line[1]], $lines)];
    }
}
