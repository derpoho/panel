<?php

namespace App\Mcp\Support;

use App\Mcp\ToolError;
use App\Support\Monitors\HostResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * An image an agent points at by URL (a logo for a service, a machine or a
 * group, a blog image), fetched the safe way: public HTTPS hosts only, no
 * redirects into private networks, at most 2 MB unless the caller allows
 * more, and only PNG, JPEG, WebP or GIF (never SVG, which can carry
 * scripts). Comes back as an upload, so it's stored exactly like one from
 * the dashboard.
 */
final class RemoteImage
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    private const TYPES = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];

    public function __construct(private readonly HostResolver $resolver) {}

    public function fetch(string $url, int $maxBytes = self::MAX_BYTES): UploadedFile
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        if (! is_string($host) || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            throw new ToolError('Give the image as a public https:// URL.');
        }
        $addresses = $this->resolver->resolve($host);
        if ($addresses === []) {
            throw new ToolError('That address isn’t reachable from ServerOS, or points into a private network.');
        }
        $ip = str_contains($addresses[0], ':') ? "[{$addresses[0]}]" : $addresses[0];
        $port = $parts['port'] ?? 443;

        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'ServerOS-MCP/1.0', 'Accept' => 'image/png,image/jpeg,image/webp,image/gif'])
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]],
                    // Stop as soon as it's too big. (Guzzle refuses CURLOPT_MAXFILESIZE.)
                    'progress' => function (int $total, int $downloaded) use ($maxBytes): void {
                        if ($total > $maxBytes || $downloaded > $maxBytes) {
                            throw new ToolError('The image must be under '.round($maxBytes / 1024 / 1024).' MB.');
                        }
                    },
                ])
                ->get($url);
        } catch (Throwable $e) {
            // Too big comes back wrapped in the HTTP client's own exceptions.
            for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof ToolError) {
                    throw $cause;
                }
            }
            throw new ToolError("Couldn’t download {$url}.");
        }
        if (! $response->successful()) {
            throw new ToolError("Downloading {$url} answered {$response->status()}".($response->redirect() ? ' (a redirect: use the final address)' : '').'.');
        }

        $body = $response->body();
        if ($body === '' || strlen($body) > $maxBytes) {
            throw new ToolError('The image must be under '.round($maxBytes / 1024 / 1024).' MB.');
        }
        $info = @getimagesizefromstring($body);
        if ($info === false || ! isset(self::TYPES[$info[2]])) {
            throw new ToolError('That isn’t a PNG, JPEG, WebP or GIF image. (SVGs aren’t accepted: they can carry scripts.)');
        }
        if ($info[0] > 4000 || $info[1] > 4000) {
            throw new ToolError('The image is too big: at most 4000×4000 pixels.');
        }

        $path = tempnam(sys_get_temp_dir(), 'mcp-icon-');
        file_put_contents($path, $body);

        return new UploadedFile($path, 'icon.'.self::TYPES[$info[2]], $info['mime'], null, true);
    }
}
