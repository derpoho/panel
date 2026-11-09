<?php

namespace App\Support\Domains;

use App\Models\Activity;
use App\Models\Workspace;
use App\Models\WorkspaceDomain;
use App\Support\Extensions\Extensions;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Business workspaces' own domains. Requests to an active one are served
 * as if they'd come to the workspace's subdomain of ours (ResolveCustomDomain),
 * links made for that subdomain point at the domain instead (mapRoot), and
 * a status page can have a hostname of its own or a path on the
 * dashboard's. MCP and the API stay on our subdomain, where clients were
 * set up.
 */
final class CustomDomains
{
    private const CACHE_KEY = 'workspace-domains:map:v1';

    /** Paths never served for a status page on the dashboard's domain. */
    public const RESERVED_PATHS = ['api', 'mcp', 'login', 'logout', 'register', 'settings', 'machines', 'services', 'workflows', 'monitors', 'backups', 'logs', 'members', 'activity', 'billing', 'oauth', 'admin', 'docs', 'build', 'storage', 'images'];

    /**
     * What every request needs to know, cached: the dashboard hostname for
     * each workspace slug, and every active hostname with what it serves.
     *
     * @return array{slugs: array<string, string>, hosts: array<string, array{workspace: int, slug: string, purpose: string, page: string|null, path: string|null}>}
     */
    public static function map(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $slugs = [];
            $hosts = [];
            $domains = WorkspaceDomain::query()->with('workspace.fleetStatusPage')->where('state', 'active')->orWhereNotNull('path')->get();

            foreach ($domains as $domain) {
                $workspace = $domain->workspace;
                if ($domain->purpose === WorkspaceDomain::DASHBOARD && $domain->hostname !== null && $domain->active()) {
                    $slugs[$workspace->slug] = $domain->hostname;
                    $hosts[$domain->hostname] = ['workspace' => $workspace->id, 'slug' => $workspace->slug, 'purpose' => WorkspaceDomain::DASHBOARD, 'page' => null, 'path' => null];
                }
            }
            foreach ($domains->where('purpose', WorkspaceDomain::STATUS) as $domain) {
                $workspace = $domain->workspace;
                $page = $domain->status_page_slug ?? $workspace->fleetStatusPage?->slug;
                if ($page === null) {
                    continue;
                }
                if ($domain->hostname !== null && $domain->active()) {
                    $hosts[$domain->hostname] = ['workspace' => $workspace->id, 'slug' => $workspace->slug, 'purpose' => WorkspaceDomain::STATUS, 'page' => $page, 'path' => null];
                } elseif ($domain->onPath() && isset($slugs[$workspace->slug])) {
                    $hosts[$slugs[$workspace->slug]] = ['workspace' => $workspace->id, 'slug' => $workspace->slug, 'purpose' => WorkspaceDomain::DASHBOARD, 'page' => $page, 'path' => $domain->path];
                }
            }

            return ['slugs' => $slugs, 'hosts' => $hosts];
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** The workspace's own dashboard hostname, when it's active. */
    public static function dashboardHostFor(string $slug): ?string
    {
        try {
            return self::map()['slugs'][$slug] ?? null;
        } catch (Throwable) {
            // No table yet (a fresh install mid-migration): the subdomain.
            return null;
        }
    }

    /**
     * What an incoming hostname serves, or null when it's not one of ours.
     *
     * @return array{workspace: int, slug: string, purpose: string, page: string|null, path: string|null}|null
     */
    public static function resolve(string $host): ?array
    {
        $host = strtolower($host);
        // The install's own status page, at status.{domain}.
        $ours = config('serveros.status_page');
        if (is_string($ours) && $ours !== '' && $host === 'status.'.config('app.domain')) {
            return ['workspace' => 0, 'slug' => '', 'purpose' => WorkspaceDomain::STATUS, 'page' => $ours, 'path' => null];
        }
        if ($host === '' || str_ends_with($host, '.'.config('app.domain')) || $host === config('app.domain')) {
            return null;
        }

        try {
            return self::map()['hosts'][$host] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Links made for a workspace's subdomain point at its own domain once
     * it has one, except MCP and the API's, which stay where clients were
     * set up.
     */
    public static function mapRoot(string $root, mixed $route = null): string
    {
        // MCP and the API stay where clients were set up (a package's MCP
        // server too); the hand-off to the workspace's domain has to start on ours.
        if ($route instanceof Route && Str::startsWith((string) $route->getName(), ['mcp', 'api.', 'workspace.domains.go', ...app(Extensions::class)->mainDomainRoutes()])) {
            return $root;
        }
        $suffix = '.'.config('app.domain');
        $host = (string) parse_url($root, PHP_URL_HOST);
        if (! str_ends_with($host, $suffix)) {
            return $root;
        }
        $custom = self::dashboardHostFor(substr($host, 0, -strlen($suffix)));

        return $custom === null ? $root : str_replace("//{$host}", "//{$custom}", $root);
    }

    /** Why a hostname can't be used, or null when it can. */
    public static function hostnameProblem(string $hostname): ?string
    {
        $labels = explode('.', $hostname);
        $ours = strtolower((string) config('app.domain'));

        return match (true) {
            strlen($hostname) > 253 || preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/', $hostname) !== 1 => 'That isn’t a domain name, like ops.example.com.',
            $hostname === $ours || str_ends_with($hostname, ".{$ours}") => 'That’s one of ours. Use a domain of your own.',
            count($labels) < 3 => 'Use a subdomain, like ops.'.$hostname.'. Root domains can’t point at ServerOS.',
            ctype_digit(end($labels)) => 'Use a domain name, not an IP address.',
            WorkspaceDomain::query()->where('hostname', $hostname)->exists() => 'That domain is already connected to a workspace.',
            default => null,
        };
    }

    /**
     * Connect a hostname to a workspace for a purpose, replacing the one it
     * had for it. With a path instead of a hostname, the status page is
     * served on the dashboard's domain.
     */
    public function connect(Workspace $workspace, string $purpose, ?string $hostname, ?string $path = null, ?string $page = null): WorkspaceDomain
    {
        $existing = $workspace->domains()->where('purpose', $purpose)->first();

        $cloudflare = null;
        if ($hostname !== null) {
            $cloudflare = app(Cloudflare::class)->create($hostname);
        }
        if ($existing !== null) {
            $this->disconnect($existing, false);
        }

        $domain = $workspace->domains()->create([
            'purpose' => $purpose,
            'hostname' => $hostname,
            'path' => $path,
            'status_page_slug' => $page,
            'cloudflare_id' => $cloudflare['id'] ?? null,
            'state' => $hostname === null ? 'active' : 'pending',
            'verified_at' => $hostname === null ? now() : null,
        ]);
        if ($cloudflare !== null) {
            $this->apply($domain, $cloudflare);
        }
        self::forget();
        Activity::record($workspace, 'domain.add', $hostname ?? "/{$path}", meta: ['purpose' => $purpose]);

        return $domain;
    }

    /**
     * Ask Cloudflare how the hostname is doing, and note it. Returns
     * whether it just became active.
     */
    public function refresh(WorkspaceDomain $domain, bool $nudge = false): bool
    {
        if ($domain->cloudflare_id === null) {
            return false;
        }

        try {
            $cloudflare = app(Cloudflare::class);
            $result = $nudge && $domain->ssl_state !== 'active' ? $cloudflare->recheck($domain->cloudflare_id) : $cloudflare->get($domain->cloudflare_id);
        } catch (RuntimeException $e) {
            $domain->forceFill(['checked_at' => now(), 'error' => $e->getMessage()])->save();

            return false;
        }

        $was = $domain->state;
        $this->apply($domain, $result);
        $became = $was !== 'active' && $domain->active();
        if ($became || $was !== $domain->state) {
            self::forget();
        }
        if ($became) {
            Activity::system($domain->workspace_id, 'domain.active', (string) $domain->hostname);
        }

        return $became;
    }

    public function disconnect(WorkspaceDomain $domain, bool $record = true): void
    {
        if ($domain->cloudflare_id !== null) {
            try {
                app(Cloudflare::class)->delete($domain->cloudflare_id);
            } catch (RuntimeException $e) {
                report($e);
            }
        }
        $domain->delete();
        self::forget();
        if ($record) {
            Activity::record($domain->workspace_id, 'domain.remove', $domain->hostname ?? "/{$domain->path}", meta: ['purpose' => $domain->purpose]);
        }
    }

    /**
     * Cloudflare's view of the hostname onto ours: active once it's proxied
     * and its certificate is out; the records the customer still needs; and
     * failed when Cloudflare gave up.
     *
     * @param  array<string, mixed>  $result
     */
    private function apply(WorkspaceDomain $domain, array $result): void
    {
        $status = (string) ($result['status'] ?? 'pending');
        $ssl = (string) ($result['ssl']['status'] ?? 'initializing');
        $active = $status === 'active' && $ssl === 'active';
        $failed = in_array($status, ['blocked', 'moved', 'deleted'], true) || in_array($ssl, ['validation_timed_out', 'issuance_timed_out', 'deployment_timed_out', 'deleted'], true);

        $records = [['type' => 'CNAME', 'name' => $domain->hostname, 'value' => Cloudflare::cnameTarget()]];
        $ownership = $result['ownership_verification'] ?? null;
        if (! $active && is_array($ownership) && isset($ownership['name'], $ownership['value']) && $status !== 'active') {
            $records[] = ['type' => strtoupper((string) ($ownership['type'] ?? 'TXT')), 'name' => $ownership['name'], 'value' => $ownership['value']];
        }
        $errors = collect((array) ($result['verification_errors'] ?? []))
            ->merge(collect((array) ($result['ssl']['validation_errors'] ?? []))->pluck('message'))
            ->filter(fn ($e) => is_string($e) && $e !== '')
            ->unique()
            ->implode(' ');

        $domain->forceFill([
            'state' => $active ? 'active' : ($failed ? 'failed' : 'pending'),
            'ssl_state' => $ssl,
            'verification' => $active ? null : ['records' => $records],
            'error' => $errors === '' ? null : mb_substr($errors, 0, 1000),
            'verified_at' => $active ? ($domain->verified_at ?? now()) : null,
            'checked_at' => now(),
        ])->save();
    }
}
