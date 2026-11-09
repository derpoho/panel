<?php

namespace App\Support;

final class DaemonFacts
{
    /** @param array<string, mixed> $facts */
    public static function provider(array $facts): ?string
    {
        $provider = $facts['hosting']['provider'] ?? null;

        return in_array($provider, ['AWS', 'Google Cloud', 'Azure', 'Hetzner', 'DigitalOcean', 'Linode'], true)
            ? $provider
            : null;
    }

    /**
     * The machine's public IPv4 address from its interfaces, if it has one.
     * The daemon may reach the panel over IPv6, but people point domains
     * with A records and type the IPv4 address, so that's the one to show.
     *
     * @param  array<string, mixed>  $facts
     */
    public static function publicAddress(array $facts): ?string
    {
        foreach ($facts['interfaces'] ?? [] as $interface) {
            if (preg_match('/^(lo|docker|br-|veth|virbr|cni|flannel|tailscale|wg)/', (string) ($interface['name'] ?? ''))) {
                continue;
            }
            foreach ($interface['addresses'] ?? [] as $address) {
                $address = explode('/', (string) $address)[0];
                if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $address;
                }
            }
        }

        return null;
    }

    /** Where Docker's default bridge usually is, when the scan doesn't say. */
    public const DEFAULT_BRIDGE = '172.17.0.1';

    /**
     * The address of Docker's default bridge (docker0) on the machine, which
     * containers reach the host on. It's 172.17.0.1 unless Docker picked
     * another subnet: one already in use, a custom bip, Docker in Docker.
     *
     * @param  array<string, mixed>  $facts
     */
    public static function dockerBridge(array $facts): string
    {
        foreach ($facts['interfaces'] ?? [] as $interface) {
            if (($interface['name'] ?? null) !== 'docker0') {
                continue;
            }
            foreach ($interface['addresses'] ?? [] as $address) {
                $address = explode('/', (string) $address)[0];
                if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return $address;
                }
            }
        }

        return self::DEFAULT_BRIDGE;
    }

    /**
     * Where the machine should say it lives: its public IPv4 when the facts
     * have one, otherwise what it had. Local test nodes keep their loopback
     * address, which the panel relies on to reach them.
     *
     * @param  array<string, mixed>  $facts
     */
    public static function address(array $facts, ?string $current): ?string
    {
        if (in_array($current, ['127.0.0.1', '::1'], true)) {
            return $current;
        }

        return self::publicAddress($facts) ?? $current;
    }
}
