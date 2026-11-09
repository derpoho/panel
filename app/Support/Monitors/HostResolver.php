<?php

namespace App\Support\Monitors;

/**
 * Turns a host into the addresses a check may connect to. Private,
 * loopback, link-local and reserved addresses are refused, so a monitor
 * can't be pointed at the panel's own network (cloud metadata, the
 * gateway, the database). Checks then connect to the address resolved
 * here, so a DNS change between the check and the connect can't sneak
 * one past.
 */
class HostResolver
{
    /**
     * @return list<string> usable addresses, best first; empty if none
     */
    public function resolve(string $host): array
    {
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->allowed($host) ? [$host] : [];
        }

        $addresses = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $addresses[] = $ip;
            }
        }
        if ($addresses === []) {
            $addresses = array_values(array_filter((array) @gethostbynamel($host)));
        }

        // A name that resolves to anything private is refused outright.
        foreach ($addresses as $ip) {
            if (! $this->allowed($ip)) {
                return [];
            }
        }

        // IPv4 first: not every panel host has IPv6 out.
        usort($addresses, fn (string $a, string $b) => str_contains($a, ':') <=> str_contains($b, ':'));

        return $addresses;
    }

    public function allowed(string $ip): bool
    {
        if (config('monitors.allow_private')) {
            return filter_var($ip, FILTER_VALIDATE_IP) !== false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && ! str_starts_with($ip, '100.64.')
            && ! str_starts_with(strtolower($ip), '::ffff:');
    }
}
