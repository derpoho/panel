<?php

namespace App\Support\Monitors;

use App\Models\Monitor;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Runs checks. HTTP checks in one batch go out together; each connects to
 * the address HostResolver approved and doesn't follow redirects (a 3xx
 * counts as up unless an exact status is asked for).
 */
class MonitorProbe
{
    public const USER_AGENT = 'ServerOS-Monitor/1.0 (+https://serveros.com)';

    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * @param  iterable<Monitor>  $monitors
     * @return array<int, ProbeResult> by monitor id
     */
    public function check(iterable $monitors): array
    {
        $results = [];
        $http = [];

        foreach ($monitors as $monitor) {
            if ($monitor->type === 'tcp') {
                $results[$monitor->id] = $this->tcp($monitor);

                continue;
            }

            $target = $this->httpTarget($monitor);
            if ($target instanceof ProbeResult) {
                $results[$monitor->id] = $target;
            } else {
                $http[$monitor->id] = [$monitor, $target];
            }
        }

        if ($http !== []) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (array $item) => $pool->as((string) $item[0]->id)
                    ->withHeaders(['User-Agent' => self::USER_AGENT, 'Accept' => '*/*'])
                    ->withOptions([
                        'allow_redirects' => false,
                        'http_errors' => false,
                        'timeout' => $item[0]->timeout_seconds,
                        'connect_timeout' => min(10, $item[0]->timeout_seconds),
                        'curl' => [CURLOPT_RESOLVE => [$item[1]]],
                    ])
                    ->get((string) $item[0]->url),
                $http,
            ));

            foreach ($http as $id => [$monitor]) {
                $results[$id] = $this->httpResult($monitor, $responses[(string) $id] ?? null);
            }
        }

        return $results;
    }

    /**
     * The curl "host:port:address" pin for a monitor's URL, or why it
     * can't be checked.
     */
    private function httpTarget(Monitor $monitor): string|ProbeResult
    {
        $parts = parse_url((string) $monitor->url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? null;

        if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || $host === '') {
            return ProbeResult::fail('Not a valid http or https address.');
        }

        $addresses = $this->resolver->resolve($host);
        if ($addresses === []) {
            return ProbeResult::fail($this->unresolved($host));
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $ip = str_contains($addresses[0], ':') ? "[{$addresses[0]}]" : $addresses[0];

        return trim($host, '[]').":{$port}:{$ip}";
    }

    private function httpResult(Monitor $monitor, mixed $response): ProbeResult
    {
        if ($response instanceof ConnectionException || $response instanceof Throwable) {
            return ProbeResult::fail($this->describe($response->getMessage(), $monitor->timeout_seconds));
        }
        if (! $response instanceof Response) {
            return ProbeResult::fail('No response.');
        }

        $seconds = $response->handlerStats()['total_time'] ?? null;
        $ms = is_numeric($seconds) ? (int) round($seconds * 1000) : null;
        $status = $response->status();

        $expected = $monitor->expected_status;
        if ($expected !== null ? $status !== $expected : ($status < 200 || $status >= 400)) {
            return ProbeResult::fail(
                $expected !== null ? "Returned {$status}, expected {$expected}." : "Returned {$status}.",
                $ms,
                $status,
            );
        }

        if ($monitor->keyword !== null && $monitor->keyword !== ''
            && ! str_contains(mb_strtolower($response->body()), mb_strtolower($monitor->keyword))) {
            return ProbeResult::fail("“{$monitor->keyword}” isn’t on the page.", $ms, $status);
        }

        return new ProbeResult(true, $ms, $status);
    }

    private function tcp(Monitor $monitor): ProbeResult
    {
        $host = (string) $monitor->host;
        $addresses = $this->resolver->resolve($host);
        if ($addresses === [] || $monitor->port === null) {
            return ProbeResult::fail($this->unresolved($host));
        }

        $ip = str_contains($addresses[0], ':') ? "[{$addresses[0]}]" : $addresses[0];
        $start = hrtime(true);
        $socket = @stream_socket_client("tcp://{$ip}:{$monitor->port}", $errno, $error, $monitor->timeout_seconds);
        $ms = (int) round((hrtime(true) - $start) / 1_000_000);

        if ($socket === false) {
            return ProbeResult::fail(match (true) {
                $errno === 110 || str_contains(strtolower($error), 'timed out') => "Timed out after {$monitor->timeout_seconds} s.",
                $errno === 111 || str_contains(strtolower($error), 'refused') => 'Connection refused.',
                default => $error !== '' ? ucfirst($error).'.' : 'Could not connect.',
            });
        }
        fclose($socket);

        return new ProbeResult(true, $ms);
    }

    /**
     * When the HTTPS certificate on the monitor's address expires, or null
     * if it can't be read.
     */
    public function certificateExpiry(Monitor $monitor): ?CarbonImmutable
    {
        $parts = parse_url((string) $monitor->url);
        $host = $parts['host'] ?? null;
        if (! is_string($host) || ! $monitor->isHttps()) {
            return null;
        }

        $addresses = $this->resolver->resolve($host);
        if ($addresses === []) {
            return null;
        }

        $ip = str_contains($addresses[0], ':') ? "[{$addresses[0]}]" : $addresses[0];
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'SNI_enabled' => true,
            'peer_name' => trim($host, '[]'),
        ]]);
        $socket = @stream_socket_client('ssl://'.$ip.':'.($parts['port'] ?? 443), $errno, $error, 5, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            return null;
        }

        $certificate = stream_context_get_params($socket)['options']['ssl']['peer_certificate'] ?? null;
        fclose($socket);
        $parsed = $certificate ? openssl_x509_parse($certificate) : false;

        return is_array($parsed) && isset($parsed['validTo_time_t'])
            ? CarbonImmutable::createFromTimestamp($parsed['validTo_time_t'])
            : null;
    }

    private function unresolved(string $host): string
    {
        $ip = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false;

        if ($ip || $this->resolvesAtAll($host)) {
            return 'Private and internal addresses can’t be monitored from the panel.';
        }

        return "Couldn’t find {$host} in DNS.";
    }

    private function resolvesAtAll(string $host): bool
    {
        return (@dns_get_record($host, DNS_A | DNS_AAAA) ?: @gethostbynamel($host)) ? true : false;
    }

    /** curl's errors, as a person would put them. */
    private function describe(string $message, int $timeout): string
    {
        return match (true) {
            str_contains($message, 'cURL error 28') => "Timed out after {$timeout} s.",
            str_contains($message, 'cURL error 7') => 'Connection refused.',
            str_contains($message, 'cURL error 6') => 'Couldn’t resolve the host.',
            str_contains($message, 'cURL error 60'), str_contains($message, 'cURL error 51') => 'The certificate isn’t valid for this address.',
            str_contains($message, 'cURL error 35') => 'The TLS handshake failed.',
            str_contains($message, 'cURL error 52') => 'The server closed the connection without replying.',
            str_contains($message, 'cURL error 56') => 'The connection was reset.',
            default => preg_replace('/^cURL error \d+: /', '', strtok($message, '(') ?: $message) ?? $message,
        };
    }
}
