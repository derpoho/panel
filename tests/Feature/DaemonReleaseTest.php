<?php

use App\Models\DaemonRelease;
use App\Models\Machine;
use App\Support\DaemonUpdates;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->releases = sys_get_temp_dir().'/serveros-releases-'.uniqid();
    config()->set('daemon.releases_path', $this->releases);
});

afterEach(fn () => File::deleteDirectory($this->releases));

/** The fake binary for a version and arch: its bytes name it. */
function releaseBinary(string $version, string $arch): string
{
    return "serverosd {$version} {$arch}";
}

function releasePayload(array $overrides = []): array
{
    $payload = [
        'version' => 'v0.1.1-pre.1', 'arch' => 'amd64', 'channel' => 'canary',
        'signature' => base64_encode(str_repeat('s', 64)),
        'min_from' => null, 'notes_url' => null, ...$overrides,
    ];

    return ['sha256' => hash('sha256', releaseBinary($payload['version'], $payload['arch'] ?? 'arm64')), ...$payload];
}

/** Put a release's binary where the releases address serves it from. */
function serveRelease(string $version, string $arch): void
{
    File::ensureDirectoryExists(DaemonRelease::path($version));
    file_put_contents(DaemonRelease::path($version, DaemonRelease::fileName($version, $arch)), releaseBinary($version, $arch));
}

test('CI registers releases with the deploy token, and daemons read the latest for their channel', function () {
    config()->set('daemon.deploy_token', 'ci-secret');
    config()->set('daemon.releases_url', 'https://serveros.com/releases/daemon');

    serveRelease('v0.1.1-pre.1', 'amd64');
    serveRelease('v0.1.0', 'amd64');

    $this->postJson('/api/daemon/releases', releasePayload())->assertUnauthorized();
    $this->withToken('ci-secret')->postJson('/api/daemon/releases', releasePayload())->assertCreated();
    $this->withToken('ci-secret')->postJson('/api/daemon/releases', releasePayload(['version' => 'v0.1.0', 'channel' => 'stable']))->assertCreated();

    // Stable machines don't see canary releases; canary ones see the newest of both.
    $this->getJson('/api/daemon/releases/latest?channel=stable&arch=x86_64')
        ->assertOk()
        ->assertJson(['version' => 'v0.1.0', 'url' => 'https://serveros.com/releases/daemon/v0.1.0/serverosd-v0.1.0-linux-amd64']);
    $this->getJson('/api/daemon/releases/latest?channel=canary&arch=x86_64')->assertJson(['version' => 'v0.1.1-pre.1']);
    $this->getJson('/api/daemon/releases/latest?channel=stable&arch=aarch64')->assertNotFound();
});

test('versions order like semver, prereleases included', function () {
    expect(DaemonRelease::compare('v0.1.0-pre.2', 'v0.1.0-pre.1'))->toBe(1)
        ->and(DaemonRelease::compare('0.1.0-pre.10', '0.1.0-pre.9'))->toBe(1)
        ->and(DaemonRelease::compare('v0.1.0', 'v0.1.0-pre.9'))->toBe(1)
        ->and(DaemonRelease::compare('v0.1.0-pre.1', 'v0.1.0'))->toBe(-1)
        ->and(DaemonRelease::compare('0.2.0-pre.1', '0.1.9'))->toBe(1)
        ->and(DaemonRelease::compare('1.0.0-alpha', '1.0.0-beta'))->toBe(-1)
        ->and(DaemonRelease::compare('v1.0.0', '1.0.0+build'))->toBe(0);
});

test('CI uploads the files first, and a release whose binary isn’t served is refused', function () {
    config()->set('daemon.deploy_token', 'ci-secret');
    $upload = fn (string $file, string $body, ?string $token = 'ci-secret') => $this->call('PUT', "/api/daemon/releases/v0.2.0-pre.1/{$file}", [], [], [], array_filter([
        'HTTP_AUTHORIZATION' => $token ? "Bearer {$token}" : null, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/octet-stream',
    ]), $body);

    // Nothing is offered before its binary is here.
    $this->withToken('ci-secret')->postJson('/api/daemon/releases', releasePayload(['version' => 'v0.2.0-pre.1', 'channel' => 'stable']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sha256');

    $upload('serverosd-v0.2.0-pre.1-linux-amd64', releaseBinary('v0.2.0-pre.1', 'amd64'), null)->assertUnauthorized();
    $upload('../../.env', 'x')->assertNotFound();
    $upload('install.sh', 'x')->assertStatus(422);
    $upload('serverosd-v0.2.0-pre.1-linux-amd64', 'not the signed binary')->assertCreated();
    $this->withToken('ci-secret')->postJson('/api/daemon/releases', releasePayload(['version' => 'v0.2.0-pre.1', 'channel' => 'stable']))
        ->assertUnprocessable();

    $upload('serverosd-v0.2.0-pre.1-linux-amd64', releaseBinary('v0.2.0-pre.1', 'amd64'))
        ->assertCreated()
        ->assertJson(['sha256' => hash('sha256', releaseBinary('v0.2.0-pre.1', 'amd64'))]);
    $upload('SHA256SUMS', "abc  serverosd-v0.2.0-pre.1-linux-amd64\n")->assertCreated();
    expect(file_get_contents("{$this->releases}/v0.2.0-pre.1/SHA256SUMS"))->toContain('serverosd-v0.2.0-pre.1-linux-amd64');

    $this->withToken('ci-secret')->postJson('/api/daemon/releases', releasePayload(['version' => 'v0.2.0-pre.1', 'channel' => 'stable']))
        ->assertCreated();
    $this->getJson('/api/daemon/releases/latest?channel=stable&arch=x86_64')->assertJson(['version' => 'v0.2.0-pre.1']);

    // New machines install the newest stable release too.
    expect(trim(file_get_contents("{$this->releases}/stable/VERSION")))->toBe('v0.2.0-pre.1');
});

test('a canary release leaves what new machines install alone', function () {
    config()->set('daemon.deploy_token', 'ci-secret');
    serveRelease('v0.1.0', 'amd64');
    serveRelease('v0.1.1-canary.1', 'amd64');

    $this->withToken('ci-secret')->postJson('/api/daemon/releases', releasePayload(['version' => 'v0.1.0', 'channel' => 'stable']))->assertCreated();
    $this->withToken('ci-secret')->postJson('/api/daemon/releases', releasePayload(['version' => 'v0.1.1-canary.1']))->assertCreated();

    expect(trim(file_get_contents("{$this->releases}/stable/VERSION")))->toBe('v0.1.0');
});

test('releases can be registered and promoted from the command line', function () {
    $file = tempnam(sys_get_temp_dir(), 'm').'-arm64.json';
    file_put_contents($file, json_encode(releasePayload(['arch' => null])));

    $this->artisan('daemon:release', ['manifests' => [$file]])->assertSuccessful();
    expect(DaemonRelease::query()->sole()->only('arch', 'channel'))->toBe(['arch' => 'arm64', 'channel' => 'canary']);

    $this->artisan('daemon:release', ['manifests' => [$file], '--channel' => 'stable'])->assertSuccessful();
    expect(DaemonRelease::query()->sole()->channel)->toBe('stable');
    // Only amd64 decides stable/VERSION, and there's no amd64 build here.
    expect(file_exists("{$this->releases}/stable/VERSION"))->toBeFalse();
});

/** An online machine running a daemon version on an architecture. */
function updatingMachine(string $name, string $arch, ?string $version, bool $online = true): Machine
{
    $machine = Machine::factory()->create(['name' => $name]);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => $online ? now() : now()->subHour(),
        'daemon_version' => $version,
        'facts' => ['arch' => $arch],
    ])->save();

    return $machine;
}

/** @return list<array{uid: string, control: array<string, mixed>}> */
function controlsSent(): array
{
    return collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn ($r) => str_ends_with($r->url(), '/control'))
        ->map(fn ($r) => ['uid' => explode('/', $r->url())[4], 'control' => $r->data()['control']])
        ->values()
        ->all();
}

test('a stable release is offered to machines as soon as it’s registered', function () {
    config()->set('daemon.deploy_token', 'ci-secret');
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['ok' => true])]);

    $behind = updatingMachine('behind', 'x86_64', 'v0.1.1-pre.2');
    updatingMachine('current', 'x86_64', 'v0.1.1-pre.4');
    updatingMachine('asleep', 'x86_64', 'v0.1.1-pre.2', online: false);
    updatingMachine('arm', 'aarch64', 'v0.1.1-pre.2');

    serveRelease('v0.1.1-pre.4', 'amd64');
    $this->withToken('ci-secret')->postJson('/api/daemon/releases', releasePayload(['version' => 'v0.1.1-pre.4', 'channel' => 'stable']))->assertCreated();

    $sent = controlsSent();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['uid'])->toBe($behind->uid)
        ->and($sent[0]['control'])->toMatchArray(['type' => 'self_update', 'version' => 'v0.1.1-pre.4', 'channel' => 'stable', 'approved' => false]);

    // Canary releases wait for each daemon's own check.
    serveRelease('v0.1.1-canary.1', 'amd64');
    $this->withToken('ci-secret')->postJson('/api/daemon/releases', releasePayload(['version' => 'v0.1.1-canary.1', 'channel' => 'canary']))->assertCreated();
    expect(controlsSent())->toHaveCount(1);
});

test('daemon:push-update offers the newest release now, and says which machines it couldn’t reach', function () {
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'gateway-secret');
    $gone = updatingMachine('gone', 'aarch64', 'v0.1.1-pre.2');
    Http::fake([
        "127.0.0.1:8090/machines/{$gone->uid}/*" => Http::response([], 404),
        '127.0.0.1:8090/*' => Http::response(['ok' => true]),
    ]);
    updatingMachine('web', 'x86_64', 'v0.1.1-pre.2');
    foreach (['amd64', 'arm64'] as $arch) {
        DaemonRelease::query()->create(releasePayload(['version' => 'v0.1.1-pre.4', 'arch' => $arch, 'channel' => 'stable']));
    }

    $this->artisan('daemon:push-update')
        ->expectsOutputToContain('1 machines offered the update: web')
        ->expectsOutputToContain('gone: The daemon is offline.')
        ->assertSuccessful();
});

test('a release beats its prereleases when working out who’s behind', function () {
    expect(DaemonUpdates::newer('v0.1.1', 'v0.1.1-pre.4'))->toBeTrue()
        ->and(DaemonUpdates::newer('v0.1.1-pre.10', 'v0.1.1-pre.9'))->toBeTrue()
        ->and(DaemonUpdates::newer('v0.1.1-pre.4', 'v0.1.1-pre.4'))->toBeFalse()
        ->and(DaemonUpdates::newer('v0.1.1-pre.4', 'v0.1.1'))->toBeFalse()
        ->and(DaemonUpdates::newer('v0.1.1-pre.4', null))->toBeTrue();
});
