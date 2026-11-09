<?php

namespace App\Support;

use App\Models\Machine;

/**
 * A machine's firewall as its daemon last read it: what's listening beyond
 * loopback, whether the internet can reach it, and the rules that decide.
 *
 * Daemons from before the firewall was read only report what's listening.
 * Their ports have no exposure (null) and are treated as reachable, since
 * they may well be.
 */
final class Firewall
{
    /**
     * Ports that shouldn't face the internet, by what usually listens there.
     *
     * @var array<int, string>
     */
    public const SENSITIVE_PORTS = [
        2375 => 'Docker API (unencrypted)',
        2376 => 'Docker API',
        3306 => 'MySQL',
        5432 => 'PostgreSQL',
        5984 => 'CouchDB',
        6379 => 'Redis',
        8086 => 'InfluxDB',
        8123 => 'ClickHouse',
        9200 => 'Elasticsearch',
        11211 => 'Memcached',
        15672 => 'RabbitMQ admin',
        27017 => 'MongoDB',
    ];

    /**
     * Processes that are databases or caches, whatever port they use.
     *
     * @var array<string, string>
     */
    public const SENSITIVE_PROCESSES = [
        'mysqld' => 'MySQL',
        'mariadbd' => 'MariaDB',
        'postgres' => 'PostgreSQL',
        'redis-server' => 'Redis',
        'mongod' => 'MongoDB',
        'memcached' => 'Memcached',
        'clickhouse' => 'ClickHouse',
    ];

    /** The ports offered in one click. */
    public const PRESETS = [
        ['label' => 'SSH', 'port' => 22, 'proto' => 'tcp'],
        ['label' => 'HTTP', 'port' => 80, 'proto' => 'tcp'],
        ['label' => 'HTTPS', 'port' => 443, 'proto' => 'tcp'],
    ];

    /** Exposures where the internet may get through. */
    public const REACHABLE = ['open', 'docker', 'unknown', null];

    /**
     * The daemon's firewall report, or null from a daemon that doesn't read it.
     *
     * @return array<string, mixed>|null
     */
    public static function report(Machine $machine): ?array
    {
        $report = $machine->inventory['firewall'] ?? null;

        return is_array($report) ? $report : null;
    }

    /**
     * The firewall as the machine page and the assistant show it.
     *
     * @return array{supported: bool, backend: string|null, installed: bool, active: bool, defaultIncoming: string|null, unavailable: string|null, ports: list<array{port: int, proto: string, process: string|null, exposure: string|null, because: string|null, what: string|null, ssh: bool}>, rules: list<array{verdict: string, ports: string, proto: string|null, source: string|null, ipv6: bool, managed: bool, opaque: bool, text: string}>, unreadable: int, notes: list<string>, presets: list<array{label: string, port: int, proto: string}>}|null
     */
    public static function view(Machine $machine): ?array
    {
        if ($machine->inventory === null) {
            return null;
        }
        $report = self::report($machine);

        $exposures = [];
        foreach ($report['ports'] ?? [] as $port) {
            if (is_array($port)) {
                $exposures[(int) ($port['port'] ?? 0).'/'.($port['proto'] ?? 'tcp')] = $port;
            }
        }

        $ports = [];
        $internal = self::bridgeAddresses($machine);
        foreach ($machine->inventory['listeners'] ?? [] as $listener) {
            if (! is_array($listener) || ! self::isPublic((string) ($listener['address'] ?? ''), $internal)) {
                continue;
            }
            $port = (int) ($listener['port'] ?? 0);
            $proto = rtrim((string) ($listener['proto'] ?? 'tcp'), '6');
            $key = "{$port}/{$proto}";
            $process = basename((string) ($listener['exe'] ?? '')) ?: null;
            if (isset($ports[$key])) {
                $ports[$key]['process'] ??= $process;

                continue;
            }
            $ports[$key] = [
                'port' => $port,
                'proto' => $proto,
                'process' => $process,
                'exposure' => $exposures[$key]['exposure'] ?? null,
                'because' => $exposures[$key]['because'] ?? null,
                'what' => self::sensitive($port, (string) $process) ?? self::sensitiveInContainer((string) ($listener['cmdline'] ?? '')),
                'ssh' => self::isSsh($port, (string) $process),
            ];
        }
        ksort($ports, SORT_NATURAL);

        return [
            'supported' => $report !== null,
            'backend' => $report['backend'] ?? null,
            'installed' => (bool) ($report['installed'] ?? false),
            'active' => (bool) ($report['active'] ?? false),
            'defaultIncoming' => $report['default_incoming'] ?? null,
            'unavailable' => self::unavailable($report),
            'ports' => array_values($ports),
            'rules' => array_values(array_map(fn (array $rule) => [
                'verdict' => (string) ($rule['verdict'] ?? 'allow'),
                'ports' => self::portsText($rule['ports'] ?? []),
                'proto' => $rule['proto'] ?? null,
                'source' => $rule['source'] ?? null,
                'ipv6' => (bool) ($rule['ipv6'] ?? false),
                'managed' => (bool) ($rule['managed'] ?? false),
                'opaque' => (bool) ($rule['opaque'] ?? false),
                'text' => (string) ($rule['text'] ?? ''),
            ], array_filter($report['rules'] ?? [], 'is_array'))),
            'unreadable' => (int) ($report['unreadable'] ?? 0),
            // When it can't be used, unavailable already says why.
            'notes' => self::unavailable($report) === null ? array_values(array_filter($report['notes'] ?? [], 'is_string')) : [],
            'presets' => self::PRESETS,
        ];
    }

    /**
     * Why ports on this machine can't be closed from the panel, or null.
     *
     * @param  array<string, mixed>|null  $report
     */
    public static function unavailable(?array $report): ?string
    {
        $backend = (string) ($report['backend'] ?? '');

        return match (true) {
            $report === null => 'This machine’s daemon doesn’t report its firewall yet. Update it with “sudo serverosd update” to open and close ports from here.',
            $backend === 'none' => 'No firewall is set for ServerOS to use on this machine. Set firewall to ufw, nftables or iptables in /etc/serveros/daemon.toml.',
            ! ($report['installed'] ?? false) => "{$backend} isn’t installed on this machine, so nothing can close a port.",
            ! ($report['active'] ?? false) => "{$backend} is installed but turned off, so it isn’t blocking anything. Turn it on on the machine, after allowing SSH.",
            default => null,
        };
    }

    /** Why the panel won't close this port, or null. The daemon checks again. */
    public static function refuseClose(Machine $machine, int $port, string $proto): ?string
    {
        if (($reason = self::unavailable(self::report($machine))) !== null) {
            return $reason;
        }

        $listening = collect(self::view($machine)['ports'] ?? [])->firstWhere(fn (array $p) => $p['port'] === $port && $p['proto'] === $proto);
        if ($port === 22 || ($listening['ssh'] ?? false)) {
            return "Port {$port} is SSH. Closing it could lock everyone out of {$machine->name}, so ServerOS won’t.";
        }
        if (($listening['exposure'] ?? null) === 'docker') {
            return "Port {$port} is published by a Docker container, and Docker’s rules come before the firewall’s. Publish it on 127.0.0.1 instead (“127.0.0.1:{$port}:” and the container’s port, in the compose file’s ports) and redeploy.";
        }

        return null;
    }

    /**
     * Databases and caches the internet may reach.
     *
     * @return list<array{port: int, proto: string, what: string, exposure: string|null}>
     */
    public static function exposedDatabases(Machine $machine): array
    {
        $exposed = [];
        foreach (self::view($machine)['ports'] ?? [] as $p) {
            if ($p['what'] !== null && in_array($p['exposure'], self::REACHABLE, true)) {
                $exposed[] = ['port' => $p['port'], 'proto' => $p['proto'], 'what' => $p['what'], 'exposure' => $p['exposure']];
            }
        }

        return $exposed;
    }

    /** What usually listens on this port or process, when it shouldn't face the internet. */
    public static function sensitive(int $port, string $process): ?string
    {
        if (isset(self::SENSITIVE_PORTS[$port])) {
            return self::SENSITIVE_PORTS[$port];
        }
        foreach (self::SENSITIVE_PROCESSES as $name => $what) {
            if ($process !== '' && str_contains($process, $name)) {
                return $what;
            }
        }

        return null;
    }

    /**
     * Whether something listening on this address may be reached from
     * outside the machine: every address (0.0.0.0, ::), or any other but
     * loopback, link-local, Tailscale's 100.64.0.0/10 and the machine's own
     * Docker bridges (where Docker publishes a port for other containers).
     * Private addresses like 10.x and 172.31.x count: on AWS, GCP and Azure
     * the public IP is NAT'd to the instance's private one.
     *
     * @param  list<string>  $internal  addresses only the machine reaches (bridgeAddresses)
     */
    public static function isPublic(string $address, array $internal = []): bool
    {
        $address = strtolower(trim($address, '[]'));
        // An IPv6 zone (fe80::1%eth0) isn't part of the address.
        $address = explode('%', $address)[0];
        // A dual-stack socket reports an IPv4 address as ::ffff:1.2.3.4.
        if (str_starts_with($address, '::ffff:') && filter_var(substr($address, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $address = substr($address, 7);
        }
        if (in_array($address, ['0.0.0.0', '::', '*'], true)) {
            return true;
        }
        if ($address === '' || $address === 'localhost' || filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (in_array($address, $internal, true)) {
            return false;
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($address);

            return ! (
                ($long & 0xFF000000) === ip2long('127.0.0.0')        // loopback
                || ($long & 0xFFFF0000) === ip2long('169.254.0.0')   // link-local
                || ($long & 0xFFC00000) === ip2long('100.64.0.0')    // Tailscale, CGNAT
            );
        }

        return ! ($address === '::1' || str_starts_with($address, 'fe8') || str_starts_with($address, 'fe9') || str_starts_with($address, 'fea') || str_starts_with($address, 'feb'));
    }

    /**
     * What a port Docker publishes really is, from docker-proxy's command
     * line (-container-port 6379): Redis published on 6380 is still Redis.
     */
    private static function sensitiveInContainer(string $cmdline): ?string
    {
        if (! str_contains($cmdline, 'docker-proxy') || preg_match('/-container-port\s+(\d+)/', $cmdline, $m) !== 1) {
            return null;
        }

        return self::SENSITIVE_PORTS[(int) $m[1]] ?? null;
    }

    /**
     * The machine's Docker bridge addresses (docker0 and Compose's br-…),
     * from its facts: a port published on one only reaches other containers.
     *
     * @return list<string>
     */
    public static function bridgeAddresses(Machine $machine): array
    {
        $addresses = [];
        foreach ($machine->facts['interfaces'] ?? [] as $interface) {
            $name = (string) ($interface['name'] ?? '');
            if ($name !== 'docker0' && ! str_starts_with($name, 'br-')) {
                continue;
            }
            foreach ((array) ($interface['addresses'] ?? []) as $address) {
                $addresses[] = explode('/', (string) $address)[0];
            }
        }

        return $addresses;
    }

    private static function isSsh(int $port, string $process): bool
    {
        return $port === 22 || str_starts_with($process, 'sshd');
    }

    /** @param  array<mixed>  $ranges */
    private static function portsText(array $ranges): string
    {
        $parts = array_map(function ($range) {
            $start = (int) ($range['start'] ?? 0);
            $end = (int) ($range['end'] ?? $start);

            return $start === $end ? (string) $start : "{$start}-{$end}";
        }, array_filter($ranges, 'is_array'));

        return $parts === [] ? 'any' : implode(', ', $parts);
    }
}
