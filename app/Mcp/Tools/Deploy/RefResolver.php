<?php

namespace App\Mcp\Tools\Deploy;

use App\Mcp\ToolError;
use App\Models\Workspace;
use App\Support\Deploys\DeployRequest;
use App\Support\GitHub\GitHubApp;
use App\Support\Monitors\HostResolver;
use App\Support\ServiceTemplates\RepositoryDetector;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * The commit a branch or tag of a public repository points at now. The
 * daemon only deploys exact commits, so the deploy dialog asks first: for
 * GitHub it asks GitHub's API, as RepositoryDetector does. Other hosts are
 * asked the way `git ls-remote` would, over Git's smart HTTP protocol,
 * the safe way: public HTTPS hosts only, pinned to the address checked,
 * redirects followed by hand and checked again.
 */
final class RefResolver
{
    /** The longest list of branches and tags read from a Git host. */
    private const MAX_BYTES = 8 * 1024 * 1024;

    private const MAX_REDIRECTS = 3;

    /** The private repository's token, while resolving on GitHub. */
    private ?string $token = null;

    public function __construct(private readonly HostResolver $hosts) {}

    /**
     * @param  string|null  $ref  a branch, a tag, a commit, or null for the default branch
     * @return array{commit: string, ref: string|null}
     */
    public function resolve(string $repo, ?string $ref, ?Workspace $workspace = null): array
    {
        $this->token = null;
        $ref = $ref === null ? null : trim($ref);
        if ($ref === '' || $ref === null) {
            $ref = null;
        } elseif (preg_match(DeployRequest::COMMIT_PATTERN, $ref)) {
            return ['commit' => strtolower($ref), 'ref' => null];
        } elseif (! self::validRef($ref)) {
            throw new ToolError('ref must be a branch, a tag or a full 40-character commit.');
        }

        try {
            DeployRequest::assertPublicRepo($repo);
        } catch (ValidationException $e) {
            throw new ToolError(Deploys::explain($e));
        }

        $parts = parse_url($repo) ?: [];
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (in_array($host, ['github.com', 'www.github.com'], true) && ! isset($parts['port'])
            && preg_match('#^/([A-Za-z0-9-]+)/([A-Za-z0-9._-]+?)(?:\.git)?/?$#', (string) ($parts['path'] ?? ''), $m)) {
            $this->token = $workspace === null ? null : app(GitHubApp::class)->tokenFor($workspace, $m[1], $m[2]);

            return $this->viaGitHub($m[1], $m[2], $ref, $workspace);
        }

        return $this->viaGit($parts, $ref);
    }

    /** A name Git would take for a branch or tag, kept to the plain characters. */
    public static function validRef(string $ref): bool
    {
        return strlen($ref) <= 255
            && preg_match('#^[A-Za-z0-9._/@+-]+$#', $ref) === 1
            && ! str_starts_with($ref, '/') && ! str_starts_with($ref, '-')
            && ! str_ends_with($ref, '/') && ! str_ends_with($ref, '.') && ! str_ends_with($ref, '.lock')
            && ! str_contains($ref, '..') && ! str_contains($ref, '//') && ! str_contains($ref, '@{');
    }

    /**
     * As the deploy dialog's detection does: the repository's default
     * branch unless one is named, then that branch's (or tag's) commit.
     *
     * @return array{commit: string, ref: string|null}
     */
    private function viaGitHub(string $owner, string $name, ?string $ref, ?Workspace $workspace): array
    {
        if ($ref === null) {
            $repo = $this->github("repos/{$owner}/{$name}");
            if ($repo === null || ! is_string($repo['default_branch'] ?? null)) {
                throw new ToolError(RepositoryDetector::notFound($workspace, $owner));
            }
            $ref = $repo['default_branch'];
        }

        $commit = $this->github("repos/{$owner}/{$name}/commits/".rawurlencode($ref));
        $sha = is_array($commit) ? ($commit['sha'] ?? null) : null;
        if (! is_string($sha) || ! preg_match(DeployRequest::COMMIT_PATTERN, $sha)) {
            throw new ToolError("Couldn’t find {$ref} in {$owner}/{$name}: give a branch, a tag or a commit that exists there (and check ServerOS can read the repository).");
        }

        return ['commit' => strtolower($sha), 'ref' => $ref];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function github(string $path): ?array
    {
        $token = $this->token ?? config('services.github.token');

        try {
            $response = Http::timeout(8)
                ->withHeaders(['Accept' => 'application/vnd.github+json', 'User-Agent' => 'ServerOS'])
                ->when(is_string($token) && $token !== '', fn (PendingRequest $http) => $http->withToken((string) $token))
                ->get("https://api.github.com/{$path}");
        } catch (ConnectionException) {
            throw new ToolError('Couldn’t reach GitHub. Try again in a moment.');
        }

        if ($response->status() === 403 || $response->status() === 429) {
            throw new ToolError('GitHub is rate limiting us. Try again in a few minutes, or give the full 40-character commit instead of a branch.');
        }

        $data = $response->successful() ? $response->json() : null;

        return is_array($data) ? $data : null;
    }

    /**
     * Any other public HTTPS Git host: its list of branches and tags, as
     * `git ls-remote` reads it.
     *
     * @param  array<string, mixed>  $parts  the repository URL, parsed
     * @return array{commit: string, ref: string|null}
     */
    private function viaGit(array $parts, ?string $ref): array
    {
        $url = 'https://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').rtrim((string) ($parts['path'] ?? ''), '/').'/info/refs?service=git-upload-pack';

        $response = $this->fetch($url);
        for ($hop = 0; $response->redirect() && $hop < self::MAX_REDIRECTS; $hop++) {
            $url = $this->follow($url, (string) $response->header('Location'));
            $response = $this->fetch($url);
        }

        if (in_array($response->status(), [401, 403, 404], true)) {
            throw new ToolError('That repository doesn’t exist, or it’s private. Only public repositories can be deployed.');
        }
        $body = $response->body();
        if (! $response->successful() || strlen($body) > self::MAX_BYTES || ! str_contains(substr($body, 0, 64), '# service=git-upload-pack')) {
            throw new ToolError('That address didn’t answer like a Git repository. Check it, or give the full 40-character commit instead of a branch.');
        }

        [$refs, $head] = self::advertisement($body);

        if ($ref === null) {
            $sha = $refs['HEAD'] ?? ($head !== null ? ($refs[$head] ?? null) : null);
            if ($sha === null) {
                throw new ToolError('That repository has no commits to deploy yet.');
            }

            return ['commit' => $sha, 'ref' => $head !== null ? preg_replace('#^refs/(heads|tags)/#', '', $head) : 'HEAD'];
        }

        $candidates = [...(str_starts_with($ref, 'refs/') || $ref === 'HEAD' ? [$ref] : []), "refs/heads/{$ref}", "refs/tags/{$ref}^{}", "refs/tags/{$ref}"];
        foreach ($candidates as $name) {
            if (isset($refs[$name])) {
                return ['commit' => $refs[$name], 'ref' => $ref];
            }
        }

        throw new ToolError(preg_match('/^[0-9a-f]{4,39}$/i', $ref)
            ? 'Give the full 40-character commit: short ones only work for GitHub repositories.'
            : "Couldn’t find {$ref} in that repository: give a branch, a tag or a full 40-character commit.");
    }

    /**
     * One request, to an address checked to be public and connected to
     * exactly that address, so DNS can't change in between.
     */
    private function fetch(string $url): Response
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        if (! is_string($host) || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            throw new ToolError('Only public HTTPS repositories can be deployed.');
        }

        $addresses = $this->hosts->resolve($host);
        if ($addresses === []) {
            throw new ToolError("Couldn’t reach {$host}, or it points into a private network. Only public repositories can be deployed.");
        }
        $ip = str_contains($addresses[0], ':') ? "[{$addresses[0]}]" : $addresses[0];
        $port = $parts['port'] ?? 443;

        try {
            return Http::timeout(10)
                ->withHeaders(['User-Agent' => 'git/2.0 (ServerOS)', 'Accept' => 'application/x-git-upload-pack-advertisement'])
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"], CURLOPT_MAXFILESIZE => self::MAX_BYTES],
                ])
                ->get($url);
        } catch (ConnectionException) {
            throw new ToolError("Couldn’t reach {$host}. Check the repository address, or try again in a moment.");
        }
    }

    /** Where a redirect goes, as a full address. Only to HTTPS. */
    private function follow(string $from, string $location): string
    {
        if (str_starts_with($location, 'https://')) {
            return $location;
        }
        $parts = parse_url($from) ?: [];
        if (str_starts_with($location, '/') && ! str_starts_with($location, '//') && isset($parts['host'])) {
            return 'https://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').$location;
        }

        throw new ToolError('That repository redirects somewhere ServerOS won’t follow. Use its final https:// address.');
    }

    /**
     * Branches and tags from Git's ref advertisement (pkt-lines): name =>
     * commit, and the branch HEAD points at, when the server says.
     *
     * @return array{0: array<string, string>, 1: string|null}
     */
    public static function advertisement(string $body): array
    {
        $refs = [];
        $head = null;
        $offset = 0;
        $length = strlen($body);

        while ($offset + 4 <= $length) {
            $size = substr($body, $offset, 4);
            if (! ctype_xdigit($size)) {
                break;
            }
            $size = (int) hexdec($size);
            if ($size === 0) {
                $offset += 4;

                continue;
            }
            if ($size < 4 || $offset + $size > $length) {
                break;
            }

            $line = rtrim(substr($body, $offset + 4, $size - 4), "\n");
            $offset += $size;

            [$entry, $capabilities] = array_pad(explode("\0", $line, 2), 2, null);
            if (preg_match('/^([0-9a-f]{40}) (\S+)$/', $entry, $m) && $m[1] !== str_repeat('0', 40)) {
                $refs[$m[2]] = $m[1];
            }
            if (is_string($capabilities) && preg_match('#(?:^|\s)symref=HEAD:(\S+)#', $capabilities, $m)) {
                $head = $m[1];
            }
        }

        return [$refs, $head];
    }
}
