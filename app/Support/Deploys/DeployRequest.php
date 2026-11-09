<?php

namespace App\Support\Deploys;

use App\Models\Machine;
use App\Models\User;
use App\Support\DaemonJobQueue;
use App\Support\ServiceTemplates\RepositoryDetector;
use App\Support\ServiceTemplates\ServiceTemplate;
use App\Support\ServiceTemplates\TemplateDeployment;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What a deploy asked for from the dashboard (or through MCP) must look
 * like: the rules its input follows, the checks made before anything is
 * queued, the daemon job a plain Git deploy becomes, and what a template
 * deploy tells the person once it's queued. MachineDeployController and
 * the MCP deploy tools share it, so both accept and send exactly the same.
 */
final class DeployRequest
{
    /** A host name, like app.example.com. */
    public const DOMAIN_PATTERN = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i';

    /** A service name the daemon accepts: it names containers and Compose projects. */
    public const SERVICE_PATTERN = '/^[a-z][a-z0-9-]*$/';

    /** A full Git commit. */
    public const COMMIT_PATTERN = '/^[a-fA-F0-9]{40}$/';

    /**
     * A pasted URL ("https://x.com/") means its host. Anything that isn't
     * a string is left for validation to refuse.
     */
    public static function bareDomain(mixed $domain): mixed
    {
        return is_string($domain)
            ? preg_replace(['#^[a-z]+://#i', '#[/?\#].*$#', '#:\d+$#'], '', strtolower(trim($domain)))
            : $domain;
    }

    /**
     * A deploy of a repository's own Dockerfile.
     *
     * @return array<string, list<string>>
     */
    public static function gitRules(): array
    {
        return [
            'service' => ['required', 'string', 'max:63', 'regex:'.self::SERVICE_PATTERN],
            'repo' => ['required', 'url', 'max:500'],
            'commit' => ['required', 'string', 'regex:'.self::COMMIT_PATTERN],
            'port' => ['required', 'integer', 'between:1,65535'],
            'health_path' => ['required', 'string', 'max:200', 'regex:/^\/[\x21-\x7e]*$/'],
            'domain' => ['nullable', 'string', 'max:253', 'regex:'.self::DOMAIN_PATTERN],
            'wildcard' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * A deploy from a service template: app templates build a repository,
     * the others are ready-made stacks.
     *
     * @return array<string, list<string>>
     */
    public static function templateRules(ServiceTemplate $template): array
    {
        return [
            'service' => ['required', 'string', 'max:63', 'regex:'.self::SERVICE_PATTERN],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'repo' => [$template->isApp() ? 'required' : 'nullable', 'url', 'max:500'],
            'commit' => [$template->isApp() ? 'required' : 'nullable', 'string', 'regex:'.self::COMMIT_PATTERN],
            'health_path' => ['nullable', 'string', 'max:200', 'regex:/^\/[\x21-\x7e]*$/'],
            'domain' => ['nullable', 'string', 'max:253', 'regex:'.self::DOMAIN_PATTERN],
            'wildcard' => ['sometimes', 'boolean'],
            'env' => ['nullable', 'array'],
            'env.*' => ['nullable', 'string', 'max:2000'],
            'extra_env' => ['nullable', 'string', 'max:10000'],
            'php' => ['nullable', 'string', Rule::in(RepositoryDetector::PHP_VERSIONS)],
        ];
    }

    /**
     * The names a deploy is served at: the domain, and with wildcard every
     * subdomain one level under it (*.domain), each of which gets its own
     * certificate the first time it's visited (on-demand TLS in the
     * daemon's Caddy, so no DNS provider is needed).
     *
     * @return list<string>
     */
    public static function domains(?string $domain, bool $wildcard = false): array
    {
        if (blank($domain)) {
            return [];
        }
        $domain = strtolower($domain);

        return $wildcard ? [$domain, '*.'.$domain] : [$domain];
    }

    /**
     * Whether a deploy's domains include every subdomain of its first one.
     *
     * @param  array<int, mixed>  $domains
     */
    public static function isWildcard(array $domains): bool
    {
        $first = $domains[0] ?? null;

        return is_string($first) && in_array('*.'.$first, $domains, true);
    }

    /**
     * A deploy's own domains, without its *.domain: what settings mention.
     *
     * @param  array<int, mixed>  $domains
     * @return list<string>
     */
    public static function plainDomains(array $domains): array
    {
        return array_values(array_filter($domains, fn ($domain) => is_string($domain) && ! str_starts_with($domain, '*.')));
    }

    /**
     * Wildcard subdomains need a domain to sit under, and a daemon that
     * gives each subdomain its own certificate: older ones would ask for a
     * wildcard certificate they can't get.
     */
    public static function assertWildcard(Machine $machine, ?string $domain, bool $wildcard, string $field = 'wildcard'): void
    {
        if (! $wildcard) {
            return;
        }
        if (blank($domain)) {
            throw ValidationException::withMessages([$field => 'Add a domain first: wildcard subdomains sit under it.']);
        }
        if (! DaemonJobQueue::canWildcard($machine)) {
            throw ValidationException::withMessages([$field => 'This machine’s daemon is too old for wildcard subdomains. It updates itself, or run “sudo serverosd update” on the server.']);
        }
    }

    /**
     * Only HTTPS repositories, and never a password in the address: it
     * would be kept with the deploy. Private GitHub ones get a token as
     * each deploy is sent (DaemonJobQueue).
     */
    public static function assertPublicRepo(string $repo): void
    {
        $parts = parse_url($repo);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw ValidationException::withMessages(['repo' => 'Use an HTTPS Git repository address, without a password or token in it.']);
        }
    }

    /**
     * Every deploy builds or runs containers. Say so before queueing one
     * that can only fail. What the panel knows comes from the machine's
     * last scan, which is hourly: Docker installed since then doesn't show
     * yet. So a refusal also asks the machine to look again now, and a
     * deploy a few seconds later goes through.
     */
    public static function assertDocker(Machine $machine, ?User $user = null): void
    {
        if ($machine->hasDocker() !== false) {
            return;
        }

        $rescanning = $user !== null && app(DaemonJobQueue::class)->rescan($machine, $user) !== null;

        throw ValidationException::withMessages(['form' => $rescanning
            ? 'Docker isn’t showing on '.$machine->name.' yet, so ServerOS is checking the machine again now. If you’ve just installed it, deploy again in a few seconds. If not, run `curl -fsSL https://get.docker.com | sh` on it as root first.'
            : 'Docker isn’t installed on '.$machine->name.'. Run `curl -fsSL https://get.docker.com | sh` on it as root, then deploy again.']);
    }

    /**
     * The daemon's job for a repository built with its own Dockerfile.
     *
     * @param  array<string, mixed>  $data  validated by gitRules()
     * @return array<string, mixed>
     */
    public static function gitJob(array $data): array
    {
        return [
            'type' => 'deploy',
            'service' => $data['service'],
            'repo' => $data['repo'],
            'commit' => strtolower($data['commit']),
            'dockerfile' => 'Dockerfile',
            'env' => (object) [],
            'health' => ['path' => $data['health_path'], 'expected_status' => 200, 'timeout_secs' => 30, 'retries' => 5],
            'domains' => self::domains($data['domain'] ?? null, (bool) ($data['wildcard'] ?? false)),
            'port' => (int) $data['port'],
        ];
    }

    /**
     * What the person is told once a template deploy is queued: the
     * generated secrets (shown once, not kept by the panel), where it will
     * open, and the template's notes.
     *
     * @param  array<string, mixed>  $data  validated by templateRules()
     * @return array{template: string, generated: list<array{key: string, label: string, value: string}>, kept: list<string>, url: string|null, localUrl: string|null, tunnel: string|null, port: int, notes: string|null}
     */
    public static function templateResult(TemplateDeployment $deployment, array $data): array
    {
        $template = $deployment->template;
        $machine = $deployment->machine;

        return [
            'template' => $template->name,
            'generated' => $deployment->generated,
            'kept' => $deployment->kept,
            // Without a domain a service only listens on the machine's own
            // 127.0.0.1, so there's no public URL to give; say how to reach it.
            // A local test node is reachable through its Caddy instead.
            'url' => $template->web && (filled($data['domain'] ?? null) || $machine->localAddress($deployment->port) !== null) ? $deployment->url : null,
            'localUrl' => $template->web && ! $template->isApp() ? "http://127.0.0.1:{$deployment->port}" : null,
            'tunnel' => $template->web && ! $template->isApp() && filled($machine->ip_address) && $machine->localAddress($deployment->port) === null
                ? "ssh -N -L {$deployment->port}:127.0.0.1:{$deployment->port} root@{$machine->ip_address}"
                : null,
            'port' => $deployment->port,
            'notes' => $template->notesFor($machine),
        ];
    }
}
