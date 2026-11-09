<?php

namespace App\Support\Domains;

use App\Models\Machine;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Before deploying behind a domain: does its DNS point at the machine?
 * Once it does, the machine's Caddy gets the certificate on its own. The
 * deploy dialogs ask through DomainCheckController; MCP asks directly.
 */
class DomainCheck
{
    /** Cloudflare's proxy addresses: a proxied record hides the machine's IP. */
    private const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    public function __construct(private readonly DomainResolver $resolver) {}

    /**
     * ready (it points here), missing (no record yet), proxied (behind
     * Cloudflare's proxy) or elsewhere, with the record to add.
     *
     * @return array{domain: string, status: string, expected: string|null, found: list<string>, record: array{type: string, name: string, value: string}|null}
     */
    public function check(Machine $machine, string $domain): array
    {
        return $this->lookup($machine, strtolower($domain), strtolower($domain));
    }

    /**
     * The same for every subdomain of a domain: looks up a made-up name one
     * level under it, which only resolves through a *.domain record.
     *
     * @return array{domain: string, status: string, expected: string|null, found: list<string>, record: array{type: string, name: string, value: string}|null}
     */
    public function checkWildcard(Machine $machine, string $domain): array
    {
        $domain = strtolower($domain);
        $probe = 'serveros-check-'.bin2hex(random_bytes(4)).'.'.$domain;

        return [...$this->lookup($machine, $probe, '*.'.$domain), 'domain' => '*.'.$domain];
    }

    /**
     * @return array{domain: string, status: string, expected: string|null, found: list<string>, record: array{type: string, name: string, value: string}|null}
     */
    private function lookup(Machine $machine, string $domain, string $recordName): array
    {
        $expected = $machine->ip_address ?: null;
        $found = $this->resolver->addresses($domain);

        $status = match (true) {
            $found === [] => 'missing',
            $expected !== null && in_array($expected, $found, true) => 'ready',
            IpUtils::checkIp($found[0], self::CLOUDFLARE) => 'proxied',
            default => 'elsewhere',
        };

        return [
            'domain' => $domain,
            'status' => $status,
            'expected' => $expected,
            'found' => $found,
            'record' => $expected === null ? null : [
                'type' => str_contains($expected, ':') ? 'AAAA' : 'A',
                'name' => $recordName,
                'value' => $expected,
            ],
        ];
    }
}
