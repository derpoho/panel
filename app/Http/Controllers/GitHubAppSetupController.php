<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\GitHubAppSetting;
use App\Models\GitHubInstallation;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use App\Support\Edition;
use App\Support\GitHub\GitHubApp;
use App\Support\GitHub\StoredGitHubApp;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A self-hosted install needs a GitHub App of its own before workspaces can
 * deploy private repositories. This makes one from a manifest (GitHub fills
 * everything in) or saves one made by hand.
 */
class GitHubAppSetupController extends Controller
{
    private const STATE_KEY = 'github_app_manifest_state';

    private const STATE_TTL_SECONDS = 3600;

    public function __construct(private readonly GitHubApp $github) {}

    public static function canManage(?User $user, Workspace $workspace): bool
    {
        return Edition::installAdmin($user, $workspace);
    }

    public function show(Request $request, Workspace $workspace): Response
    {
        abort_unless(self::canManage($request->user(), $workspace), 404);

        $state = Crypt::encryptString((string) json_encode([
            'workspace' => $workspace->id,
            'user' => $request->user()->id,
            'at' => now()->getTimestamp(),
        ]));
        $request->session()->put(self::STATE_KEY, $state);
        $stored = GitHubApp::stored();
        $host = (string) parse_url(self::base(), PHP_URL_HOST);

        return Inertia::render('workspace/settings/GitHubApp', [
            'workspace' => $workspace->toPageProps(),
            'app' => GitHubApp::fromEnvironment()
                ? ['source' => 'environment', 'name' => GitHubApp::setting('slug'), 'owner' => null, 'url' => null, 'id' => GitHubApp::setting('id')]
                : ($stored === null ? null : [
                    'source' => 'settings',
                    'name' => $stored->name ?? $stored->slug,
                    'owner' => $stored->owner_login,
                    'url' => $stored->html_url ?? 'https://github.com/apps/'.rawurlencode($stored->slug),
                    'id' => $stored->app_id,
                ]),
            'connected' => $workspace->githubInstallations()->count(),
            'urls' => self::urls(),
            'manifest' => self::manifest(),
            'webhooks' => self::reachable(),
            'state' => $state,
            'defaultName' => Str::limit('ServerOS '.$host, 34, ''),
            'webhookSecret' => Str::random(40),
        ]);
    }

    #[Audited]
    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        abort_unless(self::canManage($request->user(), $workspace), 404);
        abort_if(GitHubApp::fromEnvironment(), 409, 'This install’s GitHub App is set in .env.');

        $data = $request->validate([
            'app_id' => ['required', 'string', 'regex:/^\d{1,12}$/'],
            'client_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'client_secret' => ['required', 'string', 'max:128'],
            'private_key' => ['required', 'string', 'max:10000'],
            'webhook_secret' => ['nullable', 'string', 'max:128'],
        ], [
            'app_id.regex' => 'The App ID is a number, at the top of the app’s page on GitHub.',
        ]);

        $key = GitHubApp::readKey($data['private_key']);
        if (openssl_pkey_get_private($key) === false) {
            throw ValidationException::withMessages(['private_key' => 'That isn’t a private key. Paste the whole .pem file GitHub gave you, from -----BEGIN to -----END.']);
        }
        $app = $this->github->describe($data['app_id'], $key);
        if ($app === null) {
            throw ValidationException::withMessages(['private_key' => 'GitHub didn’t accept this private key for app '.$data['app_id'].'. Check the App ID, and that the key is one of this app’s.']);
        }
        if (! $this->clientCredentialsWork($data['client_id'], $data['client_secret'])) {
            throw ValidationException::withMessages(['client_secret' => 'GitHub didn’t accept that client ID and secret together.']);
        }

        $this->save($request->user(), $workspace, [
            'app_id' => $data['app_id'],
            'slug' => $app['slug'],
            'name' => $app['name'],
            'owner_login' => $app['owner'],
            'html_url' => $app['html_url'],
            'client_id' => $data['client_id'],
            'client_secret' => $data['client_secret'],
            'private_key' => $key,
            'webhook_secret' => filled($data['webhook_secret'] ?? null) ? $data['webhook_secret'] : null,
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Saved {$app['slug']}. Now connect your GitHub account."]);

        return to_route('workspace.github.app', ['workspace' => $workspace->slug]);
    }

    public function created(Request $request): RedirectResponse
    {
        $state = (string) $request->query('state');
        $expected = $request->session()->pull(self::STATE_KEY);
        $workspace = is_string($expected) && $state !== '' && hash_equals($expected, $state) ? $this->workspaceFromState($request, $state) : null;
        if ($workspace === null) {
            return redirect()->route('workspaces.index')->with('status', 'That GitHub App setup link has expired. Open Settings › General › Set up GitHub and try again.');
        }
        $back = $workspace->url().'/settings/github/app';
        abort_if(GitHubApp::fromEnvironment(), 409, 'This install’s GitHub App is set in .env.');

        $app = $this->github->convertManifest((string) $request->query('code'));
        if ($app === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'GitHub made the app but wouldn’t hand over its keys here. The link only works once, within an hour. Delete the app on GitHub and try again, or set it up by hand.']);

            return redirect()->away($back);
        }

        $this->save($request->user(), $workspace, [
            'app_id' => $app['id'],
            'slug' => $app['slug'],
            'name' => $app['name'],
            'owner_login' => $app['owner'],
            'html_url' => $app['html_url'],
            'client_id' => $app['client_id'],
            'client_secret' => $app['client_secret'],
            'private_key' => $app['pem'],
            'webhook_secret' => $app['webhook_secret'],
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Made {$app['slug']} on GitHub. Now connect your GitHub account."]);

        return redirect()->away($back);
    }

    #[Audited]
    public function destroy(Request $request, Workspace $workspace): RedirectResponse
    {
        abort_unless(self::canManage($request->user(), $workspace), 404);
        abort_if(GitHubApp::fromEnvironment(), 409, 'This install’s GitHub App is set in .env.');

        $stored = GitHubApp::stored();
        if ($stored !== null) {
            DB::transaction(function () {
                GitHubInstallation::query()->delete();
                GitHubAppSetting::query()->delete();
            });
            app(StoredGitHubApp::class)->forget();
            Cache::forget("github:bot-id:{$stored->slug}");
            Activity::record($workspace, 'github.app_removed', $stored->slug);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Removed the GitHub App from ServerOS. To delete it on GitHub too, open the app’s settings there.']);

        return to_route('workspace.github.app', ['workspace' => $workspace->slug]);
    }

    /** @param  array<string, string|null>  $values */
    private function save(User $user, Workspace $workspace, array $values): void
    {
        DB::transaction(function () use ($user, $values) {
            $previous = GitHubAppSetting::query()->latest('id')->first();
            if ($previous !== null && $previous->app_id !== $values['app_id']) {
                // Installations belong to the app they were made for.
                GitHubInstallation::query()->delete();
            }
            GitHubAppSetting::query()->delete();
            GitHubAppSetting::query()->create([...$values, 'created_by' => $user->id]);
        });
        app(StoredGitHubApp::class)->forget();
        Activity::record($workspace, 'github.app_set_up', $values['slug']);
    }

    private function clientCredentialsWork(string $clientId, string $secret): bool
    {
        try {
            $error = Http::timeout(10)->acceptJson()->post('https://github.com/login/oauth/access_token', [
                'client_id' => $clientId,
                'client_secret' => $secret,
                'code' => 'serveros-credentials-check',
            ])->json('error');
        } catch (ConnectionException) {
            return true;
        }

        return ! in_array($error, ['incorrect_client_credentials', 'unauthorized_client'], true);
    }

    private function workspaceFromState(Request $request, string $state): ?Workspace
    {
        try {
            $data = json_decode(Crypt::decryptString($state), true);
        } catch (DecryptException) {
            return null;
        }
        if (! is_array($data) || ! is_int($data['workspace'] ?? null)
            || ($data['user'] ?? null) !== $request->user()?->id
            || now()->getTimestamp() - (int) ($data['at'] ?? 0) > self::STATE_TTL_SECONDS) {
            return null;
        }

        $workspace = Workspace::query()->find($data['workspace']);

        return $workspace !== null && self::canManage($request->user(), $workspace) ? $workspace : null;
    }

    private static function base(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /** @return array{homepage: string, callback: string, webhook: string, created: string} */
    private static function urls(): array
    {
        return [
            'homepage' => self::base(),
            'callback' => self::base().'/github/setup',
            'webhook' => self::base().'/api/github/webhook',
            'created' => self::base().'/github/app/created',
        ];
    }

    /**
     * What GitHub needs to make the app: the page adds its name and whether
     * other accounts may install it.
     *
     * @return array<string, mixed>
     */
    private static function manifest(): array
    {
        $urls = self::urls();
        $manifest = [
            'url' => $urls['homepage'],
            'redirect_url' => $urls['created'],
            'callback_urls' => [$urls['callback']],
            'request_oauth_on_install' => true,
            'setup_on_update' => true,
            'default_permissions' => ['contents' => 'write', 'pull_requests' => 'write', 'metadata' => 'read'],
        ];

        // GitHub turns down webhook URLs it can't reach, so a panel only on
        // a private network gets an app without one (no deploy on push).
        return self::reachable() ? [
            ...$manifest,
            'hook_attributes' => ['url' => $urls['webhook'], 'active' => true],
            'default_events' => ['push', 'pull_request'],
        ] : $manifest;
    }

    private static function reachable(): bool
    {
        $host = strtolower((string) parse_url(self::base(), PHP_URL_HOST));
        if ($host === '' || $host === 'localhost' || ! str_contains($host, '.')) {
            return false;
        }
        foreach (['.localhost', '.local', '.test', '.internal', '.lan', '.home.arpa', '.example'] as $private) {
            if (str_ends_with($host, $private)) {
                return false;
            }
        }
        $ip = trim($host, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return true;
    }
}
