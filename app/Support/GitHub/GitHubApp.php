<?php

namespace App\Support\GitHub;

use App\Models\GitHubInstallation;
use App\Models\Workspace;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The ServerOS GitHub App: how a workspace deploys private repositories.
 * Someone installs it on their account or organisation and picks which
 * repositories it may read; for each deploy (and each look at a repo)
 * ServerOS asks GitHub for a token that can read just that repository,
 * lasts an hour, and is never stored.
 */
final class GitHubApp
{
    private const API = 'https://api.github.com';

    /** Installation tokens last an hour; reuse one for most of that. */
    private const TOKEN_TTL_SECONDS = 50 * 60;

    /** Whether the app's details are in the env. */
    public static function enabled(): bool
    {
        return collect(['id', 'slug', 'client_id', 'client_secret', 'private_key'])
            ->every(fn (string $key) => filled(config("services.github_app.{$key}")));
    }

    /** Where to send someone to install the app, carrying our state. */
    public function installUrl(string $state): string
    {
        return 'https://github.com/apps/'.rawurlencode(self::slug()).'/installations/new?state='.rawurlencode($state);
    }

    /**
     * A token that can read one repository, from an installation on its
     * owner the workspace has connected. Null when there's none, or the
     * repository isn't one the installation was given.
     */
    public function tokenFor(Workspace $workspace, string $owner, string $repo): ?string
    {
        return $this->installationToken($workspace, $owner, $repo, false);
    }

    /**
     * A token that can push branches to one repository and open pull
     * requests on it, for a coding agent's run. Minted fresh each time (it
     * has to last the whole run) and never cached. Null when the workspace
     * hasn't connected the owner, or the app hasn't been granted write
     * access to contents and pull requests.
     */
    public function writeTokenFor(Workspace $workspace, string $owner, string $repo): ?string
    {
        return $this->installationToken($workspace, $owner, $repo, true);
    }

    /**
     * Who the app commits as, so GitHub shows its name and avatar on the
     * commit: "<slug>[bot] <id+slug[bot]@users.noreply.github.com>", with
     * the bot account's id looked up once. Null when it can't be.
     *
     * @return array{name: string, email: string}|null
     */
    public function botIdentity(): ?array
    {
        $slug = (string) config('services.github_app.slug');
        if ($slug === '') {
            return null;
        }
        $login = "{$slug}[bot]";
        $id = Cache::rememberForever("github:bot-id:{$slug}", function () use ($login) {
            try {
                $response = $this->client()->get(self::API.'/users/'.rawurlencode($login));
            } catch (ConnectionException) {
                return null;
            }

            return $response->successful() && is_int($response->json('id')) ? $response->json('id') : null;
        });
        if (! is_int($id)) {
            Cache::forget("github:bot-id:{$slug}");

            return null;
        }

        return ['name' => $login, 'email' => "{$id}+{$login}@users.noreply.github.com"];
    }

    private function installationToken(Workspace $workspace, string $owner, string $repo, bool $write): ?string
    {
        if (! self::enabled()) {
            return null;
        }

        $installations = $workspace->githubInstallations()
            ->whereRaw('lower(account_login) = ?', [strtolower($owner)])
            ->get();

        foreach ($installations as $installation) {
            $token = $this->repositoryToken($installation, $repo, $write);
            if ($token !== null) {
                return $token;
            }
        }

        return null;
    }

    /**
     * The latest commit on a branch: read with the workspace's installation
     * when it has one on the owner, else as anyone (a public repository).
     */
    public function headCommit(Workspace $workspace, string $owner, string $repo, string $branch): ?string
    {
        $token = $this->tokenFor($workspace, $owner, $repo);
        $client = $token === null ? $this->client() : $this->client()->withToken($token);

        try {
            $response = $client->get(self::API.'/repos/'.rawurlencode($owner).'/'.rawurlencode($repo).'/commits/'.rawurlencode($branch));
        } catch (ConnectionException) {
            return null;
        }
        $sha = $response->json('sha');

        return $response->successful() && is_string($sha) && preg_match('/^[0-9a-f]{40}$/', $sha) === 1 ? $sha : null;
    }

    /** Whether a webhook delivery was signed with the app's webhook secret. */
    public static function signed(string $body, ?string $signature): bool
    {
        $secret = (string) config('services.github_app.webhook_secret');
        if ($secret === '' || ! is_string($signature) || ! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $body, $secret), $signature);
    }

    /**
     * Whether the workspace has connected the account that owns a
     * repository, so a 404 means "not shared with ServerOS" rather than
     * "private".
     */
    public static function connected(Workspace $workspace, string $owner): bool
    {
        return $workspace->githubInstallations()->whereRaw('lower(account_login) = ?', [strtolower($owner)])->exists();
    }

    /**
     * The installation as GitHub describes it (its account), or null if
     * it's gone.
     *
     * @return array<string, mixed>|null
     */
    public function installation(int $id): ?array
    {
        $response = $this->asApp()->get(self::API."/app/installations/{$id}");

        return $response->successful() && is_array($response->json()) ? $response->json() : null;
    }

    /**
     * Whether the person who just installed the app (their OAuth code from
     * the install's redirect) can see that installation. Without this, an
     * installation id in the URL could be anyone's.
     */
    public function userCanSee(string $code, int $installationId): bool
    {
        try {
            $token = Http::timeout(10)->acceptJson()->post('https://github.com/login/oauth/access_token', [
                'client_id' => config('services.github_app.client_id'),
                'client_secret' => config('services.github_app.client_secret'),
                'code' => $code,
            ])->json('access_token');
            if (! is_string($token) || $token === '') {
                return false;
            }

            for ($page = 1; $page <= 10; $page++) {
                $response = $this->client()->withToken($token)->get(self::API.'/user/installations', ['per_page' => 100, 'page' => $page]);
                $installations = $response->json('installations');
                if (! $response->successful() || ! is_array($installations)) {
                    return false;
                }
                if (collect($installations)->contains(fn ($i) => (int) ($i['id'] ?? 0) === $installationId)) {
                    return true;
                }
                if (count($installations) < 100) {
                    return false;
                }
            }
        } catch (ConnectionException) {
            return false;
        }

        return false;
    }

    private function repositoryToken(GitHubInstallation $installation, string $repo, bool $write = false): ?string
    {
        $key = "github:token:{$installation->installation_id}:".strtolower($repo);
        $cached = $write ? null : Cache::get($key);
        if (is_string($cached)) {
            return $cached;
        }

        try {
            $response = $this->asApp()->post(self::API."/app/installations/{$installation->installation_id}/access_tokens", [
                'repositories' => [$repo],
                'permissions' => $write
                    ? ['contents' => 'write', 'pull_requests' => 'write', 'metadata' => 'read']
                    : ['contents' => 'read', 'metadata' => 'read'],
            ]);
        } catch (ConnectionException) {
            return null;
        }

        // Uninstalled on GitHub: forget it here too.
        if ($response->status() === 404) {
            $installation->delete();

            return null;
        }

        $token = $response->json('token');
        if (! $response->successful() || ! is_string($token)) {
            return null;
        }
        if (! $write) {
            Cache::put($key, $token, self::TOKEN_TTL_SECONDS);
        }

        return $token;
    }

    private function asApp(): PendingRequest
    {
        return $this->client()->withToken($this->jwt());
    }

    private function client(): PendingRequest
    {
        return Http::timeout(10)->withHeaders([
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'ServerOS',
        ]);
    }

    /** The app's own short-lived JWT, signed with its private key (RS256). */
    private function jwt(): string
    {
        $key = openssl_pkey_get_private(self::privateKey());
        if ($key === false) {
            throw new RuntimeException('GITHUB_APP_PRIVATE_KEY isn’t a valid private key.');
        }

        $encode = fn (string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        $header = $encode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $encode((string) json_encode([
            // A minute back for clock drift; GitHub allows ten at most.
            'iat' => now()->subMinute()->getTimestamp(),
            'exp' => now()->addMinutes(9)->getTimestamp(),
            'iss' => (string) config('services.github_app.id'),
        ]));
        openssl_sign("{$header}.{$payload}", $signature, $key, OPENSSL_ALGO_SHA256);

        return "{$header}.{$payload}.".$encode($signature);
    }

    /** The app's slug, also when the whole public link was pasted in. */
    private static function slug(): string
    {
        $slug = trim((string) config('services.github_app.slug'), " /\t\n");

        return str_contains($slug, '/') ? (string) str($slug)->afterLast('/') : $slug;
    }

    /** The PEM, as pasted (with real or \n newlines) or base64-encoded. */
    private static function privateKey(): string
    {
        $key = trim((string) config('services.github_app.private_key'));
        if (! str_contains($key, '-----BEGIN')) {
            $key = (string) base64_decode($key, true);
        }

        return str_replace('\n', "\n", $key);
    }
}
