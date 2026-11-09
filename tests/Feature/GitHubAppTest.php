<?php

use App\Models\DaemonJob;
use App\Models\GitHubInstallation;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\DaemonJobQueue;
use App\Support\GitHub\GitHubApp;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    config()->set('services.github_app', [
        'id' => '12345', 'slug' => 'serveros', 'client_id' => 'Iv1.abc', 'client_secret' => 'shh',
        'private_key' => base64_encode($pem),
    ]);
});

function connectedWorkspace(string $login = 'acme'): array
{
    $workspace = Workspace::factory()->create();
    $installation = $workspace->githubInstallations()->create(['installation_id' => 777, 'account_login' => $login, 'account_type' => 'Organization']);

    return [$workspace, $installation];
}

function workspaceUrl(Workspace $workspace, string $path): string
{
    return 'https://'.$workspace->host().$path;
}

test('a token is minted for just the one repository, signed as the app, and reused', function () {
    [$workspace] = connectedWorkspace();
    Http::fake(['api.github.com/app/installations/777/access_tokens' => Http::response(['token' => 'ghs_one'], 201)]);

    $github = app(GitHubApp::class);
    expect($github->tokenFor($workspace, 'ACME', 'secret-app'))->toBe('ghs_one')
        ->and($github->tokenFor($workspace, 'acme', 'secret-app'))->toBe('ghs_one')
        ->and($github->tokenFor($workspace, 'someone-else', 'secret-app'))->toBeNull();

    Http::assertSentCount(1);
    Http::assertSent(function (HttpRequest $request) {
        [$header, $payload] = explode('.', Str::after($request->header('Authorization')[0], 'Bearer '));

        return $request['repositories'] === ['secret-app']
            && $request['permissions'] === ['contents' => 'read', 'metadata' => 'read']
            && json_decode(base64_decode(strtr($header, '-_', '+/')), true)['alg'] === 'RS256'
            && json_decode(base64_decode(strtr($payload, '-_', '+/')), true)['iss'] === '12345';
    });
});

test('an installation removed on GitHub is forgotten here', function () {
    [$workspace] = connectedWorkspace();
    Http::fake(['api.github.com/*' => Http::response([], 404)]);

    expect(app(GitHubApp::class)->tokenFor($workspace, 'acme', 'secret-app'))->toBeNull()
        ->and(GitHubInstallation::count())->toBe(0);
});

test('a private repository is detected with the workspace’s token', function () {
    [$workspace] = connectedWorkspace();
    Http::fake([
        'api.github.com/app/installations/777/access_tokens' => Http::response(['token' => 'ghs_read'], 201),
        'api.github.com/repos/acme/secret-app' => fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer ghs_read')
            ? Http::response(['default_branch' => 'main']) : Http::response([], 404),
        'api.github.com/repos/acme/secret-app/commits/main' => Http::response(['sha' => str_repeat('a', 40)]),
        'api.github.com/repos/acme/secret-app/git/trees/*' => Http::response(['tree' => [['path' => 'Dockerfile']]]),
    ]);

    $this->actingAs($workspace->owner)
        ->postJson(workspaceUrl($workspace, '/services/detect'), ['repository' => 'https://github.com/acme/secret-app'])
        ->assertOk()
        ->assertJsonPath('commit', str_repeat('a', 40));
});

test('a private repository says how to connect GitHub, or to share it', function () {
    $workspace = Workspace::factory()->create();
    // GitHub says 422 when the repository isn't one the installation was given.
    Http::fake(['api.github.com/app/*' => Http::response([], 422), 'api.github.com/*' => Http::response([], 404)]);

    $this->actingAs($workspace->owner)
        ->postJson(workspaceUrl($workspace, '/services/detect'), ['repository' => 'acme/secret-app'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.repository.0', fn (string $m) => str_contains($m, 'connect GitHub in Settings'));

    $workspace->githubInstallations()->create(['installation_id' => 9, 'account_login' => 'acme']);
    $this->actingAs($workspace->owner)
        ->postJson(workspaceUrl($workspace, '/services/detect'), ['repository' => 'acme/secret-app'])
        ->assertJsonPath('errors.repository.0', fn (string $m) => str_contains($m, 'shared with ServerOS'));
});

test('a private deploy goes with a fresh token that is never stored', function () {
    [$workspace] = connectedWorkspace();
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake([
        'api.github.com/app/installations/777/access_tokens' => Http::response(['token' => 'ghs_deploy'], 201),
        '127.0.0.1:8090/*' => Http::response(['queued' => true], 202),
    ]);
    $machine = Machine::factory()->for($workspace)->create();
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'daemon_version' => '0.1.1-pre.10'])->save();

    $job = app(DaemonJobQueue::class)->deploy($machine, $workspace->owner, [
        'type' => 'deploy', 'service' => 'api', 'repo' => 'https://github.com/acme/secret-app.git', 'commit' => str_repeat('b', 40),
    ]);

    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/commands') && ($r['command']['job']['repo_token'] ?? null) === 'ghs_deploy');
    expect(DaemonJob::query()->findOrFail($job->id)->command['job'])->not->toHaveKey('repo_token');
});

test('an old daemon is told to update rather than failing to clone', function () {
    [$workspace] = connectedWorkspace();
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['api.github.com/*' => Http::response(['token' => 'ghs_deploy'], 201), '127.0.0.1:8090/*' => Http::response([], 202)]);
    $machine = Machine::factory()->for($workspace)->create();
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'daemon_version' => '0.1.1-pre.7'])->save();

    expect(fn () => app(DaemonJobQueue::class)->deploy($machine, $workspace->owner, [
        'type' => 'deploy', 'service' => 'api', 'repo' => 'https://github.com/acme/secret-app', 'commit' => str_repeat('b', 40),
    ]))->toThrow(RuntimeException::class, 'too old to deploy private repositories');
});

test('connecting sends you to install the app, and back here once GitHub confirms it’s yours', function () {
    $workspace = Workspace::factory()->create();

    $location = $this->actingAs($workspace->owner)->get(workspaceUrl($workspace, '/settings/github/connect'))->headers->get('Location');
    expect($location)->toStartWith('https://github.com/apps/serveros/installations/new?state=');
    $state = urldecode(Str::after($location, 'state='));

    Http::fake([
        'github.com/login/oauth/access_token' => Http::response(['access_token' => 'ghu_user']),
        'api.github.com/user/installations*' => Http::response(['installations' => [['id' => 4242]]]),
        'api.github.com/app/installations/4242' => Http::response(['account' => ['login' => 'acme', 'type' => 'Organization']]),
    ]);

    $this->actingAs($workspace->owner)
        ->get('/github/setup?'.http_build_query(['installation_id' => 4242, 'setup_action' => 'install', 'code' => 'abc', 'state' => $state]))
        ->assertRedirect($workspace->url().'/settings/general');

    expect($workspace->githubInstallations()->sole()->only(['installation_id', 'account_login', 'account_type']))
        ->toBe(['installation_id' => 4242, 'account_login' => 'acme', 'account_type' => 'Organization']);
});

test('someone else’s installation id, or someone else’s state, connects nothing', function () {
    $workspace = Workspace::factory()->create();
    $state = Crypt::encryptString(json_encode(['workspace' => $workspace->id, 'user' => $workspace->owner->id, 'at' => now()->getTimestamp()]));
    Http::fake([
        'github.com/login/oauth/access_token' => Http::response(['access_token' => 'ghu_user']),
        'api.github.com/user/installations*' => Http::response(['installations' => [['id' => 1]]]),
    ]);

    // An installation the person can't see on GitHub.
    $this->actingAs($workspace->owner)->get('/github/setup?'.http_build_query(['installation_id' => 4242, 'code' => 'abc', 'state' => $state]));
    // Another person replaying the owner's state.
    $this->actingAs(User::factory()->create())->get('/github/setup?'.http_build_query(['installation_id' => 1, 'code' => 'abc', 'state' => $state]))
        ->assertRedirect(route('workspaces.index'));

    expect(GitHubInstallation::count())->toBe(0);
});

test('the settings page lists connections, and one can be disconnected', function () {
    [$workspace, $installation] = connectedWorkspace();

    $this->actingAs($workspace->owner)
        ->get(workspaceUrl($workspace, '/settings/general'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('github.0.account', 'acme')
            ->where('github.0.manageUrl', 'https://github.com/organizations/acme/settings/installations/777'));

    $this->actingAs($workspace->owner)->delete(workspaceUrl($workspace, "/settings/github/{$installation->id}"))->assertRedirect();
    expect(GitHubInstallation::count())->toBe(0);
});

test('without the app in the env there’s nothing to connect', function () {
    config()->set('services.github_app.private_key', null);
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)->get(workspaceUrl($workspace, '/settings/general'))
        ->assertInertia(fn (Assert $page) => $page->where('github', null)->where('githubApp', false));
    $this->actingAs($workspace->owner)->get(workspaceUrl($workspace, '/settings/github/connect'))->assertNotFound();
});

test('if GitHub comes back without the state, the session still knows the workspace', function () {
    $workspace = Workspace::factory()->create();
    $this->actingAs($workspace->owner)->get(workspaceUrl($workspace, '/settings/github/connect'));
    Http::fake([
        'github.com/login/oauth/access_token' => Http::response(['access_token' => 'ghu_user']),
        'api.github.com/user/installations*' => Http::response(['installations' => [['id' => 55]]]),
        'api.github.com/app/installations/55' => Http::response(['account' => ['login' => 'liam', 'type' => 'User']]),
    ]);

    $this->actingAs($workspace->owner)
        ->get('/github/setup?'.http_build_query(['installation_id' => 55, 'setup_action' => 'install', 'code' => 'abc']))
        ->assertRedirect($workspace->url().'/settings/general');

    expect($workspace->githubInstallations()->value('account_login'))->toBe('liam');
});

test('the slug works even when the whole public link was pasted', function () {
    config()->set('services.github_app.slug', 'https://github.com/apps/serveroshq/');

    expect(app(GitHubApp::class)->installUrl('s'))->toBe('https://github.com/apps/serveroshq/installations/new?state=s');
});
