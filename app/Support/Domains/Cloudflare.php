<?php

namespace App\Support\Domains;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cloudflare for SaaS: each workspace domain is a custom hostname on our
 * zone. Cloudflare checks the customer's DNS points at our CNAME target,
 * issues a certificate (HTTP validation, once the CNAME is in place) and
 * proxies the hostname to our fallback origin.
 */
final class Cloudflare
{
    private const API = 'https://api.cloudflare.com/client/v4';

    public static function enabled(): bool
    {
        return filled(config('services.cloudflare.token')) && filled(config('services.cloudflare.zone_id'));
    }

    /** What customers point their domain at. */
    public static function cnameTarget(): string
    {
        return (string) config('services.cloudflare.cname_target');
    }

    /**
     * @return array<string, mixed> Cloudflare's custom hostname
     */
    public function create(string $hostname): array
    {
        return $this->send('post', '/custom_hostnames', [
            'hostname' => $hostname,
            'ssl' => [
                'method' => 'http',
                'type' => 'dv',
                'settings' => ['min_tls_version' => '1.2'],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        return $this->send('get', '/custom_hostnames/'.rawurlencode($id));
    }

    /** Gone already is fine. */
    public function delete(string $id): void
    {
        try {
            $this->send('delete', '/custom_hostnames/'.rawurlencode($id));
        } catch (RuntimeException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }
    }

    /**
     * Ask Cloudflare to check the hostname again now, rather than on its
     * own schedule.
     *
     * @return array<string, mixed>
     */
    public function recheck(string $id): array
    {
        return $this->send('patch', '/custom_hostnames/'.rawurlencode($id), [
            'ssl' => ['method' => 'http', 'type' => 'dv', 'settings' => ['min_tls_version' => '1.2']],
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $body = []): array
    {
        if (! self::enabled()) {
            throw new RuntimeException('Custom domains aren’t set up on this panel yet (CLOUDFLARE_API_TOKEN and CLOUDFLARE_ZONE_ID).');
        }

        try {
            $response = $this->client()->{$method}('/zones/'.config('services.cloudflare.zone_id').$path, $body === [] ? null : $body);
        } catch (ConnectionException) {
            throw new RuntimeException('Couldn’t reach Cloudflare. Try again in a minute.');
        }

        $json = $response->json();
        if (! $response->successful() || ($json['success'] ?? false) !== true) {
            $message = collect((array) ($json['errors'] ?? []))->pluck('message')->filter()->implode(' ') ?: "Cloudflare answered HTTP {$response->status()}.";

            throw new RuntimeException($message, $response->status());
        }

        return is_array($json['result'] ?? null) ? $json['result'] : [];
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(self::API)
            ->withToken((string) config('services.cloudflare.token'))
            ->acceptJson()
            ->timeout(15);
    }
}
