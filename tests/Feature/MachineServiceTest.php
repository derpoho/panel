<?php

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\DaemonRelease;
use App\Models\Machine;
use App\Models\McpToolCall;
use App\Models\ServiceSample;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ConsoleLock;
use Illuminate\Http\Client\Request as GatewayRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * An online machine with two discovered services: a managed container and
 * an unmanaged systemd unit.
 *
 * @return array{0: Workspace, 1: Machine, 2: string}
 */
function serviceMachine(): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
            ['key' => 'docker:abc123def456', 'name' => 'serveros-cache-redis-1', 'kind' => 'cache', 'manager' => 'compose',
                'status' => 'running', 'ports' => [6380], 'confidence' => 90, 'origin' => 'created', 'details' => ['managed' => 'true', 'image' => 'redis:7-alpine']],
            ['key' => 'systemd:nginx.service', 'name' => 'nginx', 'kind' => 'web_server', 'manager' => 'systemd',
                'status' => 'running', 'ports' => [80], 'confidence' => 90, 'details' => []],
        ]],
        'service_stats' => ['docker:abc123def456' => ['cpu' => 1.5, 'memUsed' => 9_000_000, 'memLimit' => null, 'pids' => 6, 'netRx' => 0, 'netTx' => 0, 'startedAt' => 100, 'restarts' => 0, 'at' => 200]],
    ])->save();

    return [$workspace, $machine, 'https://'.$workspace->host().'/machines/'.$machine->id.'/services/'];
}

/** The job the gateway received last. */
function lastSentJob(): array
{
    $request = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (GatewayRequest $r) => str_ends_with($r->url(), '/commands'))
        ->last();

    return json_decode($request->body(), true)['command']['job'];
}

test('a service page shows the service, its stats and its history', function () {
    [$workspace, $machine, $url] = serviceMachine();
    $now = now()->getTimestamp();
    foreach ([[$now - 120, 1.0, 1000], [$now - 60, 3.0, 3000], [$now - 7200, 50.0, 9]] as [$ts, $cpu, $rx]) {
        ServiceSample::query()->create(['machine_id' => $machine->id, 'service' => 'docker:abc123def456', 'ts' => $ts, 'cpu' => $cpu, 'mem_used' => 100, 'net_rx' => $rx, 'net_tx' => 0, 'pids' => 6]);
    }

    $this->actingAs($workspace->owner)->get($url.'docker:abc123def456')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/ServiceShow')
            ->where('service.name', 'serveros-cache-redis-1')
            ->where('service.managed', true)
            ->where('service.container', true)
            ->where('stats.pids', 6)
            ->where('range', '1h')
            // The two-hour-old reading is outside the hour.
            ->has('history', 2)
            ->where('history.1.cpu', 3)
            // Network is a rate: 2000 bytes over 60 seconds.
            ->where('history.1.rx', 33.3)
            ->where('links', [])
            ->etc());

    // A local test node links its ports through the node's Caddy.
    config()->set('daemon.local_node_port', '8088');
    $machine->update(['ip_address' => '127.0.0.1']);
    $this->actingAs($workspace->owner)->get($url.'docker:abc123def456')
        ->assertInertia(fn (Assert $page) => $page->where('links', ['http://6380.localhost:8088'])->etc());

    $this->actingAs($workspace->owner)->get($url.'docker:nope')->assertNotFound();
});

test('actions queue the matching daemon job for the service', function () {
    [$workspace, $machine, $url] = serviceMachine();
    $actions = $url.'docker:abc123def456/actions';

    $this->actingAs($workspace->owner)->postJson($actions, ['action' => 'restart'])->assertAccepted();
    expect(lastSentJob())->toBe(['type' => 'service_action', 'service' => 'docker:abc123def456', 'action' => 'restart']);

    $this->actingAs($workspace->owner)->postJson($actions, ['action' => 'logs', 'lines' => 100])->assertAccepted();
    expect(lastSentJob())->toMatchArray(['type' => 'service_logs', 'lines' => 100]);

    expect(DaemonJob::query()->where('service', 'docker:abc123def456')->pluck('kind')->sort()->values()->all())
        ->toBe(['service_action', 'service_logs']);
});

test('removing needs the name typed, and only works on containers', function () {
    [$workspace, , $url] = serviceMachine();
    $actions = $url.'docker:abc123def456/actions';

    $this->actingAs($workspace->owner)->postJson($actions, ['action' => 'remove', 'confirm' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('confirm');

    $this->actingAs($workspace->owner)->postJson($actions, ['action' => 'remove', 'confirm' => 'serveros-cache-redis-1', 'delete_data' => true])
        ->assertAccepted();
    expect(lastSentJob())->toBe(['type' => 'service_remove', 'service' => 'docker:abc123def456', 'delete_data' => true, 'delete_adopted_volumes' => false]);
});

test('an adopted container can be removed, and deleting its volumes needs a second yes', function () {
    [$workspace, $machine, $url] = serviceMachine();
    $inventory = $machine->inventory;
    $inventory['services'][] = ['key' => 'docker:9f8e7d6c5b4a', 'name' => '8f2c1d0e-wings', 'kind' => 'other', 'manager' => 'docker',
        'status' => 'exited', 'ports' => [], 'confidence' => 60, 'origin' => 'discovered', 'details' => ['managed' => 'true']];
    $machine->forceFill(['inventory' => $inventory])->save();
    $actions = $url.'docker:9f8e7d6c5b4a/actions';

    $this->actingAs($workspace->owner)->postJson($actions, ['action' => 'remove', 'confirm' => '8f2c1d0e-wings'])
        ->assertAccepted();
    expect(lastSentJob())->toBe(['type' => 'service_remove', 'service' => 'docker:9f8e7d6c5b4a', 'delete_data' => false, 'delete_adopted_volumes' => false]);
    expect(Activity::query()->latest('id')->first()->meta)->toMatchArray(['adopted' => true]);

    $this->actingAs($workspace->owner)->postJson($actions, ['action' => 'remove', 'confirm' => '8f2c1d0e-wings', 'delete_data' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('delete_adopted_volumes');

    $this->actingAs($workspace->owner)->postJson($actions, ['action' => 'remove', 'confirm' => '8f2c1d0e-wings', 'delete_data' => true, 'delete_adopted_volumes' => true])
        ->assertAccepted();
    expect(lastSentJob())->toBe(['type' => 'service_remove', 'service' => 'docker:9f8e7d6c5b4a', 'delete_data' => true, 'delete_adopted_volumes' => true]);
});

test('unmanaged services can only be adopted', function () {
    [$workspace, , $url] = serviceMachine();
    $actions = $url.'systemd:nginx.service/actions';

    $this->actingAs($workspace->owner)->postJson($actions, ['action' => 'restart'])->assertStatus(409);
    $this->actingAs($workspace->owner)->postJson($actions, ['action' => 'logs'])->assertStatus(409);

    $this->actingAs($workspace->owner)->postJson($actions, ['action' => 'adopt'])->assertAccepted();
    expect(lastSentJob())->toBe(['type' => 'adopt', 'service' => 'systemd:nginx.service', 'dry_run' => false]);
});

test('viewers can look but not act', function () {
    [$workspace, , $url] = serviceMachine();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    $this->actingAs($viewer)->get($url.'docker:abc123def456')->assertOk();
    $this->actingAs($viewer)->postJson($url.'docker:abc123def456/actions', ['action' => 'stop'])->assertForbidden();
});

/**
 * Turn on 2FA for the user, with the authenticator accepting only "123456".
 */
function withTwoFactor(User $user): void
{
    $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();

    $provider = Mockery::mock(TwoFactorAuthenticationProvider::class);
    $provider->shouldReceive('verify')->andReturnUsing(fn ($secret, $code) => $code === '123456');
    app()->instance(TwoFactorAuthenticationProvider::class, $provider);
}

test('the console needs two-factor on the account and a fresh code to unlock', function () {
    [$workspace, , $url] = serviceMachine();
    $owner = $workspace->owner;
    $exec = fn () => $this->actingAs($owner)->postJson($url.'docker:abc123def456/actions', ['action' => 'exec', 'command' => 'redis-cli ping']);
    $unlock = 'https://'.$workspace->host().'/console/unlock';

    // No 2FA on the account: no console at all.
    $exec()->assertForbidden()->assertJson(['code' => 'two_factor_required']);
    $this->actingAs($owner)->postJson($unlock, ['code' => '123456'])->assertForbidden();

    withTwoFactor($owner);

    // 2FA on, but locked until a code is entered.
    $exec()->assertStatus(423)->assertJson(['code' => 'console_locked']);
    $this->actingAs($owner)->postJson($unlock, ['code' => '000000'])->assertUnprocessable()->assertJsonValidationErrors('code');

    $this->actingAs($owner)->postJson($unlock, ['code' => '123456'])->assertOk()->assertJsonStructure(['unlockedUntil']);
    $exec()->assertAccepted();
    expect(lastSentJob())->toMatchArray(['type' => 'service_exec', 'command' => 'redis-cli ping']);

    // It locks again after the window, or when asked to.
    $this->travel(ConsoleLock::MINUTES + 1)->minutes();
    $machine = Machine::query()->latest('id')->first();
    $machine->forceFill(['last_seen_at' => now()])->save();
    $exec()->assertStatus(423);

    $this->actingAs($owner)->postJson($unlock, ['code' => '123456'])->assertOk();
    $this->actingAs($owner)->postJson('https://'.$workspace->host().'/console/lock')->assertOk();
    $exec()->assertStatus(423);
});

test('repairing queues the job for adopted containers only, and goes in the activity', function () {
    [$workspace, , $url] = serviceMachine();

    $this->actingAs($workspace->owner)->postJson($url.'docker:abc123def456/actions', ['action' => 'repair'])->assertAccepted();
    expect(lastSentJob())->toBe(['type' => 'service_repair', 'service' => 'docker:abc123def456'])
        ->and(Activity::query()->latest('id')->first()->action)->toBe('service.repair');

    $this->actingAs($workspace->owner)->postJson($url.'systemd:nginx.service/actions', ['action' => 'repair'])->assertStatus(409);
});

test('any container can show its docker inspect, adopted or not, once the daemon is new enough', function () {
    [$workspace, $machine, $url] = serviceMachine();
    $inventory = $machine->inventory;
    $inventory['services'][] = ['key' => 'docker:20953b51701d', 'name' => 'shop-backend.1.q7o4n0hvag3okusmwldss5cel', 'kind' => 'app', 'manager' => 'docker',
        'status' => 'running', 'ports' => [], 'confidence' => 60, 'details' => []];
    $machine->forceFill(['inventory' => $inventory, 'daemon_version' => '0.1.1-pre.15'])->save();
    $actions = $url.'docker:20953b51701d/actions';

    // Too old: it would drop the job unread. Not offered while no release can do it.
    $this->actingAs($workspace->owner)->get($url.'docker:20953b51701d')
        ->assertInertia(fn (Assert $page) => $page->where('inspect', null));
    DaemonRelease::query()->create(['version' => '0.1.1-pre.16', 'channel' => 'stable', 'arch' => 'amd64', 'sha256' => str_repeat('a', 64), 'signature' => 'x']);
    $this->actingAs($workspace->owner)->get($url.'docker:20953b51701d')
        ->assertInertia(fn (Assert $page) => $page->where('inspect.available', false));
    $this->actingAs($workspace->owner)->postJson($actions, ['action' => 'inspect'])->assertStatus(409);

    $machine->forceFill(['daemon_version' => '0.1.1-pre.16'])->save();
    $this->actingAs($workspace->owner)->postJson($actions, ['action' => 'inspect'])->assertAccepted();
    expect(lastSentJob())->toBe(['type' => 'service_inspect', 'service' => 'docker:20953b51701d']);

    // Reads aren't activity.
    expect(Activity::query()->where('action', 'like', 'service%')->exists())->toBeFalse();

    // Not a container: nothing to inspect, and the page doesn't offer it.
    $this->actingAs($workspace->owner)->postJson($url.'systemd:nginx.service/actions', ['action' => 'inspect'])->assertStatus(409);
    $this->actingAs($workspace->owner)->get($url.'systemd:nginx.service')
        ->assertInertia(fn (Assert $page) => $page->where('inspect', null));
});

test('removing a container ServerOS only found adopts it first, then removes it once that’s done', function () {
    [$workspace, $machine, $url] = serviceMachine();
    $inventory = $machine->inventory;
    $inventory['services'][] = ['key' => 'docker:1a2b3c4d5e6f', 'name' => 'old-app', 'kind' => 'app', 'manager' => 'docker',
        'status' => 'running', 'ports' => [], 'confidence' => 60, 'origin' => 'discovered', 'details' => []];
    $machine->forceFill(['inventory' => $inventory])->save();

    $this->actingAs($workspace->owner)->postJson($url.'docker:1a2b3c4d5e6f/actions', ['action' => 'remove', 'confirm' => 'old-app'])
        ->assertAccepted();
    expect(lastSentJob())->toBe(['type' => 'adopt', 'service' => 'docker:1a2b3c4d5e6f', 'dry_run' => false]);

    $adopt = DaemonJob::query()->where('kind', 'adopt')->sole();
    $this->withoutDefer()->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', [
        'envelopes' => [['v' => 1, 'kind' => 'job_update', 'payload' => ['job_id' => $adopt->id, 'state' => 'succeeded']]],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();

    // The adopt also rescans the machine; the removal goes alongside, as the person who asked.
    $remove = DaemonJob::query()->where('kind', 'service_remove')->sole();
    expect($remove->command['job'])->toBe(['type' => 'service_remove', 'service' => 'docker:1a2b3c4d5e6f', 'delete_data' => false, 'delete_adopted_volumes' => false])
        ->and($remove->user_id)->toBe($workspace->owner_id)
        ->and($adopt->fresh()->then)->toBeNull();
});

test('a systemd service can be removed once the daemon knows how, and its data never goes', function () {
    [$workspace, $machine, $url] = serviceMachine();
    $machine->forceFill(['daemon_version' => '0.1.1-pre.16'])->save();

    $this->actingAs($workspace->owner)->get($url.'systemd:nginx.service')
        ->assertInertia(fn (Assert $page) => $page->where('removable', false));
    $this->actingAs($workspace->owner)->postJson($url.'systemd:nginx.service/actions', ['action' => 'remove', 'confirm' => 'nginx'])
        ->assertStatus(409);

    $machine->forceFill(['daemon_version' => '0.1.1-pre.17'])->save();
    $this->actingAs($workspace->owner)->get($url.'systemd:nginx.service')
        ->assertInertia(fn (Assert $page) => $page->where('removable', true));
    $this->actingAs($workspace->owner)->postJson($url.'systemd:nginx.service/actions', ['action' => 'remove', 'confirm' => 'nginx', 'delete_data' => true])
        ->assertAccepted();

    // Not adopted yet: adopted first, and the removal waiting on it keeps the data.
    expect(lastSentJob()['type'])->toBe('adopt')
        ->and(DaemonJob::query()->where('kind', 'adopt')->sole()->then['job'])
        ->toBe(['type' => 'service_remove', 'service' => 'systemd:nginx.service', 'delete_data' => false, 'delete_adopted_volumes' => false]);
});

test('a job an MCP call queues carries the call, to the daemon and back to the call log', function () {
    [$workspace, $machine] = serviceMachine();

    expect(mcpCall($workspace, mcpKey($workspace), 'restart_service', ['machine' => $machine->name, 'service' => 'docker:abc123def456'])['error'])->toBeFalse();

    $call = McpToolCall::query()->where('tool', 'restart_service')->sole();
    $job = DaemonJob::query()->where('kind', 'service_action')->sole();
    $sent = json_decode(collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->filter(fn (GatewayRequest $r) => str_ends_with($r->url(), '/commands'))->last()->body(), true)['command']['actor'];

    expect($call->call_id)->not->toBeNull()
        ->and($job->mcp_call_id)->toBe($call->call_id)
        ->and($sent)->toMatchArray(['kind' => 'user', 'via' => 'mcp', 'tool' => 'restart_service', 'call' => $call->call_id]);
});
