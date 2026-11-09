<?php

namespace App\Support\Domains;

/**
 * The addresses a domain's A and AAAA records point at, as this server's
 * resolver sees them. Bound in the container so tests can swap it out.
 */
class DomainResolver
{
    /**
     * @return list<string>
     */
    public function addresses(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_unique(array_filter(array_map(
            fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        ))));
    }
}
