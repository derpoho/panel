<?php

namespace App\Support\GitHub;

use App\Models\GitHubAppSetting;
use App\Models\GitHubInstallation;
use App\Models\Workspace;
use App\Support\Edition;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class GitHubApp
{
    private const API = 'https://api.github.com';

    private const TOKEN_TTL_SECONDS = 50 * 60;

    public static function enabled(): bool
    {
        return collect(['id', 'slug', 'client_id', 'client_secret', 'private_key'])
            ->every(fn (string $key) => filled(self::setting($key)));
    }

    /**
     * One of the app's settings. GITHUB_APP_* in .env decide when they name an
     * app; otherwise a self-hosted install uses the app it saved in Settings.
     */
    public static function setting(string $key): ?string
    {
        if (self::fromEnvironment() || ! Edition::selfHosted()) {
            $value = config("services.github_app.{$key}");

            return is_scalar($value) ? (string) $value : null;
        }

        $stored = self::stored();

        return match ($key) {
            'id' => $stored?->app_id,
            'slug' => $stored?->slug,
            'client_id' => $stored?->client_id,
            'client_secret' => $stored?->client_secret,
            'private_key' => $stored?->private_key,
            'webhook_secret' => $stored?->webhook_secret,
            default => null,
        };
    }

    public static function fromEnvironment(): bool
    {
        return filled(config('services.github_app.id'));
    }

    public static function stored(): ?GitHubAppSetting
    {
        return app(StoredGitHubApp::class)->get();
    }

    /**
     * Checks an app ID and private key belong together by signing in as the
     * app, and returns what GitHub says the app is.
     *
     * @return array{slug: string, name: string|null, owner: string|null, html_url: string|null}|null
     */
    public function describe(string $id, string $privateKey): ?array
    {
        try {
            $response = $this->client()->withToken($this->jwt($id, $privateKey))->get(self::API.'/app');
        } catch (ConnectionException|RuntimeException) {
            return null;
        }
        $slug = $response->json('slug');
        if (! $response->successful() || ! is_string($slug) || $slug === '' || (string) $response->json('id') !== $id) {
            return null;
        }

        return [
            'slug' => $slug,
            'name' => is_string($response->json('name')) ? $response->json('name') : null,
            'owner' => is_string($response->json('owner.login')) ? $response->json('owner.login') : null,
            'html_url' => is_string($response->json('html_url')) ? $response->json('html_url') : null,
        ];
    }

    /**
     * Turns the code GitHub sends back after creating an app from a manifest
     * into the app's settings. The code works once, within an hour.
     *
     * @return array{id: string, slug: string, name: string|null, owner: string|null, html_url: string|null, client_id: string, client_secret: string, pem: string, webhook_secret: string|null}|null
     */
    public function convertManifest(string $code): ?array
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,100}$/', $code) !== 1) {
            return null;
        }

        try {
            // No body at all: post() would send [], which GitHub turns down
            // with a 422, and the code only works once.
            $response = $this->client()->send('POST', self::API.'/app-manifests/'.rawurlencode($code).'/conversions');
        } catch (ConnectionException $e) {
            Log::warning('GitHub App manifest conversion: GitHub didn’t answer.', ['error' => $e->getMessage()]);

            return null;
        }
        $app = $response->json();
        if (! $response->successful() || ! is_array($app)) {
            Log::warning('GitHub App manifest conversion failed.', ['status' => $response->status(), 'message' => $response->json('message')]);

            return null;
        }
        foreach (['slug', 'client_id', 'client_secret', 'pem'] as $required) {
            if (! is_string($app[$required] ?? null) || $app[$required] === '') {
                return null;
            }
        }
        if (! is_int($app['id'] ?? null)) {
            return null;
        }

        return [
            'id' => (string) $app['id'],
            'slug' => $app['slug'],
            'name' => is_string($app['name'] ?? null) ? $app['name'] : null,
            'owner' => is_string($app['owner']['login'] ?? null) ? $app['owner']['login'] : null,
            'html_url' => is_string($app['html_url'] ?? null) ? $app['html_url'] : null,
            'client_id' => $app['client_id'],
            'client_secret' => $app['client_secret'],
            'pem' => $app['pem'],
            'webhook_secret' => is_string($app['webhook_secret'] ?? null) ? $app['webhook_secret'] : null,
        ];
    }

    public function installUrl(string $state): string
    {
        return 'https://github.com/apps/'.rawurlencode(self::slug()).'/installations/new?state='.rawurlencode($state);
    }

    public function tokenFor(Workspace $workspace, string $owner, string $repo): ?string
    {
        return $this->installationToken($workspace, $owner, $repo, false);
    }

    public function writeTokenFor(Workspace $workspace, string $owner, string $repo): ?string
    {
        return $this->installationToken($workspace, $owner, $repo, true);
    }

    /** @return array{name: string, email: string}|null */
    public function botIdentity(): ?array
    {
        $slug = (string) self::setting('slug');
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

    /**
     * @param  list<array{path: string, mode: string, content: string|null}>  $files
     *
     * @throws RuntimeException
     */
    public function openPullRequest(string $token, string $owner, string $repo, string $base, string $into, string $branch, array $files, string $message, string $title, string $body): string
    {
        $client = $this->client()->timeout(30)->withToken($token);
        $api = self::API.'/repos/'.rawurlencode($owner).'/'.rawurlencode($repo);
        $send = function (string $method, string $path, array $data = []) use ($client, $api): array {
            try {
                $response = $method === 'GET' ? $client->get($api.$path) : $client->post($api.$path, $data);
            } catch (ConnectionException) {
                throw new RuntimeException('GitHub didn’t answer, so no pull request was opened.');
            }
            if (! $response->successful() || ! is_array($response->json())) {
                throw new RuntimeException('GitHub answered '.$response->status().': '.mb_substr((string) ($response->json('message') ?? 'no reason given'), 0, 200));
            }

            return $response->json();
        };

        $tree = $send('GET', "/git/commits/{$base}")['tree']['sha'] ?? null;
        $entries = [];
        foreach ($files as $file) {
            $sha = $file['content'] === null ? null : ($send('POST', '/git/blobs', ['content' => $file['content'], 'encoding' => 'base64'])['sha'] ?? null);
            $entries[] = ['path' => $file['path'], 'mode' => $file['mode'], 'type' => 'blob', 'sha' => $sha];
        }
        $newTree = $send('POST', '/git/trees', ['base_tree' => $tree, 'tree' => $entries])['sha'] ?? null;
        $commit = $send('POST', '/git/commits', ['message' => $message, 'tree' => $newTree, 'parents' => [$base]])['sha'] ?? null;
        $send('POST', '/git/refs', ['ref' => "refs/heads/{$branch}", 'sha' => $commit]);
        $url = $send('POST', '/pulls', ['title' => $title, 'head' => $branch, 'base' => $into, 'body' => $body])['html_url'] ?? null;
        if (! is_string($url) || preg_match('#^https://github\.com/\S+/pull/\d+$#', $url) !== 1) {
            throw new RuntimeException('GitHub opened the pull request but didn’t say where.');
        }

        return $url;
    }

    public static function signed(string $body, ?string $signature): bool
    {
        $secret = (string) self::setting('webhook_secret');
        if ($secret === '' || ! is_string($signature) || ! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $body, $secret), $signature);
    }

    public static function connected(Workspace $workspace, string $owner): bool
    {
        return $workspace->githubInstallations()->whereRaw('lower(account_login) = ?', [strtolower($owner)])->exists();
    }

    /** @return array<string, mixed>|null */
    public function installation(int $id): ?array
    {
        $response = $this->asApp()->get(self::API."/app/installations/{$id}");

        return $response->successful() && is_array($response->json()) ? $response->json() : null;
    }

    /** @return array{login: string, type: string|null, repository_ids: list<int>|null}|null */
    public function confirmInstaller(string $code, int $installationId): ?array
    {
        try {
            $token = Http::timeout(10)->acceptJson()->post('https://github.com/login/oauth/access_token', [
                'client_id' => self::setting('client_id'),
                'client_secret' => self::setting('client_secret'),
                'code' => $code,
            ])->json('access_token');
            if (! is_string($token) || $token === '') {
                return null;
            }

            $account = $this->installation($installationId)['account'] ?? null;
            if (! is_array($account) || ! is_string($account['login'] ?? null)) {
                return null;
            }
            $login = $account['login'];
            $type = is_string($account['type'] ?? null) ? $account['type'] : null;
            $user = $this->client()->withToken($token);

            if ($type === 'User') {
                $id = $user->get(self::API.'/user')->json('id');

                return is_int($id) && $id === ($account['id'] ?? null)
                    ? ['login' => $login, 'type' => $type, 'repository_ids' => null]
                    : null;
            }

            $membership = $user->get(self::API.'/user/memberships/orgs/'.rawurlencode($login));
            if ($membership->successful() && $membership->json('state') === 'active' && $membership->json('role') === 'admin') {
                return ['login' => $login, 'type' => $type, 'repository_ids' => null];
            }

            $repositories = $this->repositoriesAdministered($user, $installationId);

            return $repositories === null ? null : ['login' => $login, 'type' => $type, 'repository_ids' => $repositories];
        } catch (ConnectionException) {
            return null;
        }
    }

    /** @return list<int>|null */
    private function repositoriesAdministered(PendingRequest $user, int $installationId): ?array
    {
        $theirs = $this->repositories($user, self::API."/user/installations/{$installationId}/repositories");
        $token = $this->asApp()->post(self::API."/app/installations/{$installationId}/access_tokens", [
            'permissions' => ['metadata' => 'read'],
        ])->json('token');
        $all = is_string($token) ? $this->repositories($this->client()->withToken($token), self::API.'/installation/repositories') : null;
        if ($theirs === null || $all === null || $all === []) {
            return null;
        }

        $administered = collect($theirs)
            ->filter(fn (array $repository) => ($repository['permissions']['admin'] ?? false) === true)
            ->map(fn (array $repository) => (int) ($repository['id'] ?? 0))
            ->all();
        $ids = collect($all)->map(fn (array $repository) => (int) ($repository['id'] ?? 0))->unique()->sort();

        return $ids->every(fn (int $id) => $id > 0 && in_array($id, $administered, true)) ? array_values($ids->all()) : null;
    }

    /** @return list<array<string, mixed>>|null */
    private function repositories(PendingRequest $client, string $url): ?array
    {
        $all = [];
        for ($page = 1; $page <= 10; $page++) {
            $response = $client->get($url, ['per_page' => 100, 'page' => $page]);
            $repositories = $response->json('repositories');
            if (! $response->successful() || ! is_array($repositories)) {
                return null;
            }
            foreach ($repositories as $repository) {
                if (is_array($repository)) {
                    $all[] = $repository;
                }
            }
            if (count($repositories) < 100) {
                return $all;
            }
        }

        return null;
    }

    private function repositoryToken(GitHubInstallation $installation, string $repo, bool $write = false): ?string
    {
        $key = "github:token:{$installation->id}:".strtolower($repo);
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

        if ($response->status() === 404) {
            $installation->delete();

            return null;
        }

        $token = $response->json('token');
        if (! $response->successful() || ! is_string($token) || ! $this->grantsOnly($installation, $response->json('repositories'))) {
            return null;
        }
        if (! $write) {
            Cache::put($key, $token, self::TOKEN_TTL_SECONDS);
        }

        return $token;
    }

    private function grantsOnly(GitHubInstallation $installation, mixed $repositories): bool
    {
        if ($installation->repository_ids === null) {
            return true;
        }
        if (! is_array($repositories) || $repositories === []) {
            return false;
        }

        return collect($repositories)->every(fn (mixed $repository) => is_array($repository) && $installation->allows((int) ($repository['id'] ?? 0)));
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

    private function jwt(?string $id = null, ?string $privateKey = null): string
    {
        $key = openssl_pkey_get_private(self::readKey($privateKey ?? (string) self::setting('private_key')));
        if ($key === false) {
            throw new RuntimeException('GITHUB_APP_PRIVATE_KEY isn’t a valid private key.');
        }

        $encode = fn (string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        $header = $encode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $encode((string) json_encode([
            'iat' => now()->subMinute()->getTimestamp(),
            'exp' => now()->addMinutes(9)->getTimestamp(),
            'iss' => $id ?? (string) self::setting('id'),
        ]));
        openssl_sign("{$header}.{$payload}", $signature, $key, OPENSSL_ALGO_SHA256);

        return "{$header}.{$payload}.".$encode($signature);
    }

    private static function slug(): string
    {
        $slug = trim((string) self::setting('slug'), " /\t\n");

        return str_contains($slug, '/') ? (string) str($slug)->afterLast('/') : $slug;
    }

    /** A PEM key as GitHub gives it, base64 of one, or one with \n for line breaks. */
    public static function readKey(string $key): string
    {
        $key = trim($key);
        if (! str_contains($key, '-----BEGIN')) {
            $key = (string) base64_decode($key, true);
        }

        return str_replace('\n', "\n", $key);
    }
}
