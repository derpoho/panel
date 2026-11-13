<?php

use App\Models\GitHubAppSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Entitlements\Entitlements;
use App\Support\Entitlements\SelfHostedEntitlements;
use App\Support\GitHub\GitHubApp;
use App\Support\GitHub\StoredGitHubApp;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set('services.github_app', ['id' => null, 'slug' => null, 'client_id' => null, 'client_secret' => null, 'private_key' => null, 'webhook_secret' => null]);
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $this->pem = $pem;
});

function selfHostedForGitHub(): void
{
    config(['serveros.edition' => 'self-hosted']);
    app()->forgetInstance(Entitlements::class);
    app()->singleton(Entitlements::class, fn () => new SelfHostedEntitlements);
    DB::table('install_identity')->where('id', 1)->update(['set_up_at' => now()]);
}

function onWorkspaceHost(Workspace $workspace, string $path): string
{
    return 'https://'.$workspace->host().'/'.ltrim($path, '/');
}

function fakeGitHubApp(string $id = '4242', string $slug = 'serveros-acme'): void
{
    Http::swap(new Factory);
    Http::fake([
        'api.github.com/app' => Http::response(['id' => (int) $id, 'slug' => $slug, 'name' => 'ServerOS acme', 'owner' => ['login' => 'acme'], 'html_url' => "https://github.com/apps/{$slug}"]),
        'github.com/login/oauth/access_token' => Http::response(['error' => 'bad_verification_code']),
    ]);
}

function freshStoredApp(): void
{
    app(StoredGitHubApp::class)->forget();
}

test('the setup page is only for self-hosted installs', function () {
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)->get(onWorkspaceHost($workspace, 'settings/github/app'))->assertNotFound();
    $this->actingAs($workspace->owner)->get(onWorkspaceHost($workspace, 'settings/general'))
        ->assertInertia(fn (Assert $page) => $page->where('githubSetup', null));
});

test('a self-hosted owner without an app is offered setup, with a manifest GitHub can make the app from', function () {
    selfHostedForGitHub();
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)->get(onWorkspaceHost($workspace, 'settings/general'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('github', [])
            ->where('githubSetup', ['ready' => false, 'canSetUp' => true, 'fromEnvironment' => false]));

    config(['app.url' => 'https://panel.acme.dev']);
    $base = 'https://panel.acme.dev';
    $this->actingAs($workspace->owner)->get(onWorkspaceHost($workspace, 'settings/github/app'))
        ->assertSessionHas('github_app_manifest_state')
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/settings/GitHubApp')
            ->where('app', null)
            ->where('manifest.hook_attributes.url', "{$base}/api/github/webhook")
            ->where('manifest.redirect_url', "{$base}/github/app/created")
            ->where('manifest.callback_urls', ["{$base}/github/setup"])
            ->where('manifest.request_oauth_on_install', true)
            ->where('manifest.default_permissions', ['contents' => 'write', 'pull_requests' => 'write', 'metadata' => 'read'])
            ->where('manifest.default_events', ['push', 'pull_request'])
            ->where('webhooks', true));
});

test('a panel GitHub can’t reach gets an app without a webhook, which GitHub would turn down', function () {
    selfHostedForGitHub();
    $workspace = Workspace::factory()->create();

    foreach (['https://serveros.localhost:28443', 'https://panel.home.arpa', 'https://192.168.1.20', 'https://serveros.test'] as $url) {
        config(['app.url' => $url]);
        $this->actingAs($workspace->owner)->get(onWorkspaceHost($workspace, 'settings/github/app'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('webhooks', false)
                ->missing('manifest.hook_attributes')
                ->missing('manifest.default_events')
                ->where('manifest.redirect_url', "{$url}/github/app/created"));
    }
});

test('only the workspace owner can set up the install’s GitHub App', function () {
    selfHostedForGitHub();
    $workspace = Workspace::factory()->create();
    $admin = User::factory()->create();
    $workspace->members()->attach($admin, ['role' => 'admin']);

    $this->actingAs($admin)->get(onWorkspaceHost($workspace, 'settings/github/app'))->assertNotFound();
    $this->actingAs($admin)->put(onWorkspaceHost($workspace, 'settings/github/app'), ['app_id' => '1'])->assertNotFound();
    $this->actingAs($admin)->get(onWorkspaceHost($workspace, 'settings/general'))
        ->assertInertia(fn (Assert $page) => $page->where('githubSetup.canSetUp', false));
});

test('an app made by hand is checked with GitHub, saved encrypted and used from then on', function () {
    selfHostedForGitHub();
    $workspace = Workspace::factory()->create();
    fakeGitHubApp();

    $this->actingAs($workspace->owner)->put(onWorkspaceHost($workspace, 'settings/github/app'), [
        'app_id' => '4242',
        'client_id' => 'Iv23liabc',
        'client_secret' => 'client-secret-value',
        'private_key' => $this->pem,
        'webhook_secret' => 'hook-secret-value',
    ])->assertRedirect(onWorkspaceHost($workspace, 'settings/github/app'))->assertSessionHasNoErrors();

    $row = DB::table('github_app_settings')->first();
    expect($row->app_id)->toBe('4242')
        ->and($row->slug)->toBe('serveros-acme')
        ->and($row->owner_login)->toBe('acme')
        ->and($row->client_secret)->not->toContain('client-secret-value')
        ->and($row->private_key)->not->toContain('PRIVATE KEY');

    freshStoredApp();
    expect(GitHubApp::enabled())->toBeTrue()
        ->and(GitHubApp::setting('webhook_secret'))->toBe('hook-secret-value')
        ->and(GitHubApp::signed('{}', 'sha256='.hash_hmac('sha256', '{}', 'hook-secret-value')))->toBeTrue();
    Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://api.github.com/app'
        && json_decode(base64_decode(strtr(explode('.', Str::after($request->header('Authorization')[0], 'Bearer '))[1], '-_', '+/')), true)['iss'] === '4242');

    $workspace->githubInstallations()->create(['installation_id' => 777, 'account_login' => 'acme', 'account_type' => 'Organization']);
    Http::swap(new Factory);
    Http::fake(['api.github.com/app/installations/777/access_tokens' => Http::response(['token' => 'ghs_stored'], 201)]);
    expect(app(GitHubApp::class)->tokenFor($workspace, 'acme', 'private-app'))->toBe('ghs_stored');
});

test('a key GitHub doesn’t accept, or a wrong client secret, isn’t saved', function () {
    selfHostedForGitHub();
    $workspace = Workspace::factory()->create();
    $form = ['app_id' => '4242', 'client_id' => 'Iv23liabc', 'client_secret' => 'nope', 'private_key' => $this->pem];

    $this->actingAs($workspace->owner)->put(onWorkspaceHost($workspace, 'settings/github/app'), [...$form, 'private_key' => 'not a key'])
        ->assertSessionHasErrors('private_key');

    Http::swap(new Factory);
    Http::fake(['api.github.com/app' => Http::response(['message' => 'A JSON web token could not be decoded'], 401)]);
    $this->actingAs($workspace->owner)->put(onWorkspaceHost($workspace, 'settings/github/app'), $form)
        ->assertSessionHasErrors('private_key');

    Http::swap(new Factory);
    Http::fake([
        'api.github.com/app' => Http::response(['id' => 4242, 'slug' => 'serveros-acme']),
        'github.com/login/oauth/access_token' => Http::response(['error' => 'incorrect_client_credentials']),
    ]);
    $this->actingAs($workspace->owner)->put(onWorkspaceHost($workspace, 'settings/github/app'), $form)
        ->assertSessionHasErrors('client_secret');

    expect(GitHubAppSetting::query()->count())->toBe(0);
});

test('GitHub’s manifest code is turned into the app’s settings when the state matches', function () {
    selfHostedForGitHub();
    $workspace = Workspace::factory()->create();
    $state = Crypt::encryptString((string) json_encode(['workspace' => $workspace->id, 'user' => $workspace->owner_id, 'at' => now()->getTimestamp()]));
    Http::fake(['api.github.com/app-manifests/abc123/conversions' => Http::response([
        'id' => 9001, 'slug' => 'serveros-panel', 'name' => 'ServerOS panel', 'owner' => ['login' => 'liam'],
        'html_url' => 'https://github.com/apps/serveros-panel', 'client_id' => 'Iv23lixyz', 'client_secret' => 'made-secret',
        'pem' => $this->pem, 'webhook_secret' => 'made-hook',
    ], 201)]);
    $created = 'https://'.config('app.domain').'/github/app/created';

    $this->actingAs($workspace->owner)->withSession(['github_app_manifest_state' => 'something-else'])
        ->get("{$created}?code=abc123&state=".urlencode($state))
        ->assertRedirect(route('workspaces.index'));
    expect(GitHubAppSetting::query()->count())->toBe(0);

    $this->actingAs($workspace->owner)->withSession(['github_app_manifest_state' => $state])
        ->get("{$created}?code=abc123&state=".urlencode($state))
        ->assertRedirect($workspace->url().'/settings/github/app');

    $app = GitHubAppSetting::query()->sole();
    expect($app->app_id)->toBe('9001')
        ->and($app->slug)->toBe('serveros-panel')
        ->and($app->client_secret)->toBe('made-secret')
        ->and($app->webhook_secret)->toBe('made-hook');
    // GitHub answers a [] body with a 422, so the conversion sends none.
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/app-manifests/abc123/conversions')
        && $request->method() === 'POST' && $request->body() === '');
    expect(DB::table('activities')->where('action', 'github.app_set_up')->where('workspace_id', $workspace->id)->exists())->toBeTrue();
});

test('GITHUB_APP_* in .env wins, and the page won’t change it', function () {
    selfHostedForGitHub();
    $workspace = Workspace::factory()->create();
    GitHubAppSetting::query()->create(['app_id' => '1', 'slug' => 'stored', 'client_id' => 'a', 'client_secret' => 'b', 'private_key' => 'c']);
    config()->set('services.github_app', ['id' => '777', 'slug' => 'from-env', 'client_id' => 'x', 'client_secret' => 'y', 'private_key' => base64_encode($this->pem)]);
    freshStoredApp();

    expect(GitHubApp::setting('slug'))->toBe('from-env');
    $this->actingAs($workspace->owner)->get(onWorkspaceHost($workspace, 'settings/github/app'))
        ->assertInertia(fn (Assert $page) => $page->where('app.source', 'environment')->where('app.name', 'from-env'));
    $this->actingAs($workspace->owner)->delete(onWorkspaceHost($workspace, 'settings/github/app'))->assertStatus(409);
    expect(GitHubAppSetting::query()->count())->toBe(1);
});

test('a different app, or removing it, disconnects the installations made for the old one', function () {
    selfHostedForGitHub();
    $workspace = Workspace::factory()->create();
    GitHubAppSetting::query()->create(['app_id' => '4242', 'slug' => 'serveros-acme', 'client_id' => 'a', 'client_secret' => 'b', 'private_key' => $this->pem]);
    $workspace->githubInstallations()->create(['installation_id' => 777, 'account_login' => 'acme', 'account_type' => 'Organization']);
    $form = ['client_id' => 'Iv23liabc', 'client_secret' => 's', 'private_key' => $this->pem];

    fakeGitHubApp('4242');
    $this->actingAs($workspace->owner)->put(onWorkspaceHost($workspace, 'settings/github/app'), [...$form, 'app_id' => '4242'])->assertSessionHasNoErrors();
    expect($workspace->githubInstallations()->count())->toBe(1);

    fakeGitHubApp('5555', 'serveros-other');
    $this->actingAs($workspace->owner)->put(onWorkspaceHost($workspace, 'settings/github/app'), [...$form, 'app_id' => '5555'])->assertSessionHasNoErrors();
    expect($workspace->githubInstallations()->count())->toBe(0)
        ->and(GitHubAppSetting::query()->sole()->app_id)->toBe('5555');

    $workspace->githubInstallations()->create(['installation_id' => 888, 'account_login' => 'acme', 'account_type' => 'Organization']);
    $this->actingAs($workspace->owner)->delete(onWorkspaceHost($workspace, 'settings/github/app'))
        ->assertRedirect(onWorkspaceHost($workspace, 'settings/github/app'));
    freshStoredApp();
    expect(GitHubAppSetting::query()->count())->toBe(0)
        ->and($workspace->githubInstallations()->count())->toBe(0)
        ->and(GitHubApp::enabled())->toBeFalse();
});
