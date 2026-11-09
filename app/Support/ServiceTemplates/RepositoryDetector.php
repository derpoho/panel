<?php

namespace App\Support\ServiceTemplates;

use App\Models\Workspace;
use App\Support\GitHub\GitHubApp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Works out what a GitHub repository is (a Laravel web app, an Express
 * API, a Vite site…) so it can deploy with the matching template. Public
 * ones are read as they are; private ones with a token from the
 * workspace's GitHub connection (GitHubApp).
 * Looks at the latest commit on the default branch (or the branch in the
 * URL): the files at its root, and the manifests that name a framework.
 */
class RepositoryDetector
{
    public const TYPE_WEB = 'Web app';

    public const TYPE_API = 'API';

    public const TYPE_STATIC = 'Static site';

    public const TYPE_APP = 'App';

    /** Manifests worth reading when they exist at the root. */
    public const MANIFESTS = ['package.json', 'composer.json', 'requirements.txt', 'pyproject.toml', 'Gemfile', 'Cargo.toml', 'go.mod', 'mix.exs'];

    /** The private repository's token, while detecting one. */
    private ?string $token = null;

    /**
     * @return array{detected: bool, template: string|null, framework: string|null, type: string|null, repo: string, branch: string, commit: string, reasons: list<string>}
     */
    public function detect(string $url, ?Workspace $workspace = null): array
    {
        [$owner, $name, $branch] = $this->parse($url);
        $this->token = $workspace === null ? null : app(GitHubApp::class)->tokenFor($workspace, $owner, $name);

        $repo = $this->api("repos/{$owner}/{$name}");
        if ($repo === null) {
            throw ValidationException::withMessages(['repository' => self::notFound($workspace, $owner)]);
        }

        $branch ??= (string) $repo['default_branch'];
        $commit = $this->api("repos/{$owner}/{$name}/commits/".rawurlencode($branch));
        if ($commit === null) {
            throw ValidationException::withMessages(['repository' => "Couldn’t find the {$branch} branch."]);
        }

        $sha = (string) $commit['sha'];
        $tree = $this->api("repos/{$owner}/{$name}/git/trees/{$sha}") ?? ['tree' => []];
        $files = array_column($tree['tree'] ?? [], 'path');

        $contents = [];
        foreach (self::MANIFESTS as $manifest) {
            if (in_array($manifest, $files, true)) {
                $contents[$manifest] = $this->raw($owner, $name, $sha, $manifest);
            }
        }

        $match = $this->match($files, $contents);

        return [
            'detected' => $match !== null,
            'template' => $match['template'] ?? null,
            'framework' => $match['framework'] ?? null,
            'type' => $match['type'] ?? null,
            'repo' => "https://github.com/{$owner}/{$name}.git",
            'branch' => $branch,
            'commit' => $sha,
            'reasons' => $match['reasons'] ?? [],
            // For the PHP templates: what to build on and where to check it's up.
            'php' => $match['php'] ?? null,
            'healthPath' => $match['healthPath'] ?? null,
        ];
    }

    /**
     * The first rule that fits, most specific first.
     *
     * @param  list<string>  $files
     * @param  array<string, string|null>  $contents
     * @return array{template: string, framework: string, type: string, reasons: list<string>, php?: string, healthPath?: string}|null
     */
    public function match(array $files, array $contents): ?array
    {
        $has = fn (string $file) => in_array($file, $files, true);
        $hasAny = fn (string $pattern) => preg_grep($pattern, $files) !== [];

        if ($has('Dockerfile')) {
            return $this->found('custom-dockerfile', 'Dockerfile', self::TYPE_APP, 'Has a Dockerfile at the root, so it builds as is.');
        }

        if ($has('hugo.toml') || $has('hugo.yaml') || $has('hugo.json')) {
            return $this->found('hugo', 'Hugo', self::TYPE_STATIC, 'Has a Hugo config at the root.');
        }

        if (($composer = $this->json($contents['composer.json'] ?? null)) !== null) {
            $require = array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []);

            $found = match (true) {
                isset($require['laravel/framework']) => $this->found('laravel', 'Laravel', self::TYPE_WEB, 'composer.json requires laravel/framework.'),
                isset($require['symfony/framework-bundle']) => $this->found('symfony', 'Symfony', self::TYPE_WEB, 'composer.json requires symfony/framework-bundle.'),
                default => $this->found('php', 'PHP', self::TYPE_WEB, 'Has a composer.json.'),
            };
            [$php, $why] = self::phpFor($composer);
            $found['php'] = $php;
            $found['reasons'][] = $why;
            // /up, the Laravel template's health check, came with Laravel 11.
            $laravel = self::major($composer['require']['laravel/framework'] ?? null);
            if ($laravel !== null && $laravel < 11) {
                $found['healthPath'] = '/';
                $found['reasons'][] = "Laravel {$laravel} has no /up route, so check / for health.";
            }

            return $found;
        }

        if (($package = $this->json($contents['package.json'] ?? null)) !== null) {
            $deps = array_merge($package['dependencies'] ?? [], $package['devDependencies'] ?? []);
            $bun = $has('bun.lockb') || $has('bun.lock');
            $api = ['@nestjs/core' => 'NestJS', 'express' => 'Express', 'fastify' => 'Fastify', 'hono' => 'Hono', 'koa' => 'Koa'];

            foreach (['next' => ['nextjs', 'Next.js'], 'nuxt' => ['nuxt', 'Nuxt'], '@sveltejs/kit' => ['sveltekit', 'SvelteKit']] as $dep => [$template, $label]) {
                if (isset($deps[$dep])) {
                    return $this->found($template, $label, self::TYPE_WEB, "package.json depends on {$dep}.");
                }
            }
            if (isset($deps['astro'])) {
                return $this->found('astro', 'Astro', self::TYPE_STATIC, 'package.json depends on astro.');
            }
            if (isset($deps['@remix-run/node']) || isset($deps['@remix-run/react'])) {
                return $this->found('remix', 'Remix', self::TYPE_WEB, 'package.json depends on Remix.');
            }
            if (isset($deps['@angular/core'])) {
                return $this->found('angular', 'Angular', self::TYPE_STATIC, 'package.json depends on @angular/core.');
            }
            if (isset($deps['@nestjs/core']) && ! $bun) {
                return $this->found('nestjs', 'NestJS', self::TYPE_API, 'package.json depends on @nestjs/core.');
            }
            foreach ($api as $dep => $label) {
                if (isset($deps[$dep])) {
                    return $this->found($bun ? 'bun' : 'node', $label, self::TYPE_API, "package.json depends on {$dep}.");
                }
            }
            if (isset($deps['vite']) && ! isset($package['scripts']['start'])) {
                return $this->found('vite-spa', 'Vite', self::TYPE_STATIC, 'Builds with Vite and has no server to start.');
            }

            return $bun
                ? $this->found('bun', 'Bun', self::TYPE_APP, 'Has a Bun lockfile.')
                : $this->found('node', 'Node.js', self::TYPE_APP, 'Has a package.json.');
        }

        if ($has('deno.json') || $has('deno.jsonc')) {
            return $this->found('deno', 'Deno', self::TYPE_APP, 'Has a deno.json.');
        }

        if (str_contains($contents['mix.exs'] ?? '', ':phoenix')) {
            return $this->found('phoenix', 'Phoenix', self::TYPE_WEB, 'mix.exs depends on phoenix.');
        }

        $python = strtolower(($contents['requirements.txt'] ?? '')."\n".($contents['pyproject.toml'] ?? ''));
        if (trim($python) !== '') {
            return match (true) {
                str_contains($python, 'django') => $this->found('django', 'Django', self::TYPE_WEB, 'Python dependencies include Django.'),
                str_contains($python, 'fastapi') => $this->found('fastapi', 'FastAPI', self::TYPE_API, 'Python dependencies include FastAPI.'),
                str_contains($python, 'flask') => $this->found('flask', 'Flask', self::TYPE_WEB, 'Python dependencies include Flask.'),
                default => null,
            };
        }

        if (str_contains(strtolower($contents['Gemfile'] ?? ''), 'rails')) {
            return $this->found('rails', 'Ruby on Rails', self::TYPE_WEB, 'The Gemfile includes rails.');
        }

        if ($has('go.mod')) {
            $go = $contents['go.mod'] ?? '';
            $api = preg_match('#gin-gonic/gin|labstack/echo|gofiber/fiber|go-chi/chi#', $go) === 1;

            return $this->found('go', 'Go', $api ? self::TYPE_API : self::TYPE_APP, 'Has a go.mod.');
        }

        if ($has('Cargo.toml')) {
            $api = preg_match('/^\s*(axum|actix-web|rocket|warp)\s*=/m', $contents['Cargo.toml'] ?? '') === 1;

            return $this->found('rust', 'Rust', $api ? self::TYPE_API : self::TYPE_APP, 'Has a Cargo.toml.');
        }

        if ($has('pom.xml') || $has('build.gradle') || $has('build.gradle.kts')) {
            return $this->found('spring-boot', 'Spring Boot', self::TYPE_WEB, 'Has a Maven or Gradle build.');
        }

        if ($hasAny('/\.(csproj|sln)$/')) {
            return $this->found('dotnet', '.NET', self::TYPE_WEB, 'Has a .NET project file.');
        }

        if ($has('index.html')) {
            return $this->found('static-site', 'Static site', self::TYPE_STATIC, 'Has an index.html and nothing to build.');
        }

        return null;
    }

    /** PHP versions the PHP templates can build on, oldest first. */
    public const PHP_VERSIONS = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4'];

    /**
     * The newest PHP a project runs on, from composer.json: what its Laravel
     * supports (old Laravel pins libraries that break on newer PHP), else
     * the newest version its php constraint allows.
     *
     * @param  array<string, mixed>  $composer
     * @return array{0: string, 1: string}
     */
    public static function phpFor(array $composer): array
    {
        $laravel = self::major($composer['require']['laravel/framework'] ?? null);
        if ($laravel !== null && $laravel < 11) {
            $php = match (true) {
                $laravel <= 6 => '7.4',
                $laravel <= 8 => '8.1',
                $laravel === 9 => '8.2',
                default => '8.3',
            };

            return [$php, "Laravel {$laravel} runs on PHP {$php} at the newest."];
        }

        $constraint = (string) ($composer['require']['php'] ?? '');
        if ($constraint !== '') {
            foreach (array_reverse(self::PHP_VERSIONS) as $version) {
                if (self::allows($constraint, $version)) {
                    return [$version, "composer.json asks for PHP {$constraint}."];
                }
            }
        }

        return ['8.4', 'No PHP version is pinned, so the newest.'];
    }

    /** The major version a constraint starts at (^8.65 → 8), or null. */
    private static function major(mixed $constraint): ?int
    {
        return is_string($constraint) && preg_match('/(\d+)/', $constraint, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * Whether a composer constraint allows any release of a PHP minor
     * version (8.1 means some 8.1.x): its alternatives (| or ||), each a
     * set of bounds that all have to hold, as Composer reads them.
     */
    private static function allows(string $constraint, string $version): bool
    {
        foreach (preg_split('/\s*\|\|?\s*/', trim($constraint)) ?: [] as $alternative) {
            $alternative = trim($alternative);
            if ($alternative === '') {
                continue;
            }
            // "8.1 - 8.2": from the one to the other, both included.
            if (preg_match('/^v?([\d.]+)\s+-\s+v?([\d.]+)$/', $alternative, $range) === 1) {
                $alternative = ">={$range[1]} <=".self::upTo($range[2]);
            }
            $bounds = preg_split('/[\s,]+/', $alternative) ?: [];
            foreach (["{$version}.0", "{$version}.99"] as $candidate) {
                if (collect($bounds)->every(fn (string $bound) => self::meets($candidate, $bound))) {
                    return true;
                }
            }
        }

        return false;
    }

    /** One bound, like ^8.1, ~8.1.0, >=7.4, <8.2, 8.1.* or 8.2, for a version like 8.1.99. */
    private static function meets(string $candidate, string $bound): bool
    {
        if (preg_match('/^(\^|~|>=|<=|>|<|!=|==|=)?\s*v?(\d+)(?:\.(\d+|\*))?(?:\.(\d+|\*))?/', $bound, $m) !== 1) {
            return true;
        }
        [$op, $major, $minor, $patch] = [$m[1], (int) $m[2], $m[3] ?? null, $m[4] ?? null];
        [$cMajor, $cMinor] = array_map('intval', explode('.', $candidate));

        if ($minor === '*' || $patch === '*') {
            return $cMajor === $major && ($minor === '*' || $cMinor === (int) $minor);
        }
        $low = $major.'.'.(int) ($minor ?? 0).'.'.(int) ($patch ?? 0);

        return match ($op) {
            '^' => version_compare($candidate, $low, '>=') && $cMajor === $major,
            '~' => version_compare($candidate, $low, '>=') && ($patch !== null ? $cMajor === $major && $cMinor === (int) $minor : $cMajor === $major),
            '>=' => version_compare($candidate, $low, '>='),
            '>' => version_compare($candidate, self::upTo($low), '>'),
            '<' => version_compare($candidate, $low, '<'),
            '<=' => version_compare($candidate, self::upTo($low), '<='),
            '!=' => true,
            // Exact (8.2 or =8.2.3): that minor version.
            default => $cMajor === $major && ($minor === null || $cMinor === (int) $minor),
        };
    }

    /** The last release a bound like <=8.2 or 8.1 - 8.2 still lets in: 8.2.x. */
    private static function upTo(string $version): string
    {
        $parts = explode('.', $version);

        return count(array_filter($parts, fn ($p) => $p !== '')) >= 3 ? $version : $parts[0].'.'.($parts[1] ?? '0').'.99';
    }

    /**
     * @return array{template: string, framework: string, type: string, reasons: list<string>, php?: string, healthPath?: string}
     */
    private function found(string $template, string $framework, string $type, string $reason): array
    {
        return ['template' => $template, 'framework' => $framework, 'type' => $type, 'reasons' => [$reason]];
    }

    /**
     * owner, repository and branch from a GitHub URL (or owner/repo).
     *
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function parse(string $url): array
    {
        $url = trim($url);
        $pattern = '#^(?:https?://)?(?:www\.)?github\.com/([A-Za-z0-9-]+)/([A-Za-z0-9._-]+?)(?:\.git)?(?:/tree/([^?\#]+))?/?$#';

        if (preg_match('#^([A-Za-z0-9-]+)/([A-Za-z0-9._-]+)$#', $url, $m)) {
            return [$m[1], $m[2], null];
        }
        if (preg_match($pattern, $url, $m)) {
            return [$m[1], $m[2], isset($m[3]) ? rtrim($m[3], '/') : null];
        }

        throw ValidationException::withMessages(['repository' => 'Paste a GitHub link, like https://github.com/you/app.']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function api(string $path): ?array
    {
        try {
            $response = $this->client()->get("https://api.github.com/{$path}");
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['repository' => 'Couldn’t reach GitHub. Try again in a moment.']);
        }

        if ($response->status() === 403 || $response->status() === 429) {
            throw ValidationException::withMessages(['repository' => 'GitHub is rate limiting us. Try again in a few minutes.']);
        }

        return $response->successful() ? $response->json() : null;
    }

    private function raw(string $owner, string $name, string $sha, string $file): ?string
    {
        try {
            $response = $this->client()->get("https://raw.githubusercontent.com/{$owner}/{$name}/{$sha}/{$file}");
        } catch (ConnectionException) {
            return null;
        }

        return $response->successful() ? $response->body() : null;
    }

    /**
     * Why a repository couldn't be read, and what to do about it.
     */
    public static function notFound(?Workspace $workspace, string $owner): string
    {
        return match (true) {
            ! GitHubApp::enabled() || $workspace === null => 'That repository doesn’t exist, or it’s private. Only public GitHub repositories work for now.',
            GitHubApp::connected($workspace, $owner) => "That repository doesn’t exist, or isn’t one {$owner} shared with ServerOS. Add it to the ServerOS app’s repositories on GitHub, then try again.",
            default => 'That repository doesn’t exist, or it’s private. To deploy private repositories, connect GitHub in Settings → General.',
        };
    }

    private function client(): PendingRequest
    {
        $token = $this->token ?? config('services.github.token');

        return Http::timeout(8)
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'User-Agent' => 'ServerOS'])
            ->when(is_string($token) && $token !== '', fn (PendingRequest $http) => $http->withToken((string) $token));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function json(?string $body): ?array
    {
        if ($body === null) {
            return null;
        }

        $data = json_decode($body, true);

        return is_array($data) ? $data : null;
    }
}
