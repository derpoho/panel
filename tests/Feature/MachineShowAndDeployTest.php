<?php

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function machineUrl(Workspace $workspace, Machine $machine): string
{
    return 'https://'.$workspace->host().'/machines/'.$machine->id;
}

test('a member can open only a machine in their workspace and inspect its inventory', function () {
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'inventory' => ['scanned_at' => now()->timestamp, 'complete' => true,
            'services' => [['key' => 'manual:web', 'name' => 'Web', 'kind' => 'app', 'manager' => 'manual', 'status' => 'running', 'ports' => [8080], 'confidence' => 80]],
            'listeners' => [['proto' => 'tcp', 'address' => '0.0.0.0', 'port' => 8080, 'pid' => 10, 'exe' => '/usr/bin/python3']],
        ],
    ])->save();
    $other = Machine::factory()->create();

    $this->actingAs($workspace->owner)->get(machineUrl($workspace, $machine))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('workspace/MachineShow')
        ->where('machine.id', $machine->id)
        ->where('machine.inventory.listeners.0.port', 8080)
        ->has('jobs', 0)
        ->etc());
    $this->actingAs($workspace->owner)->get(machineUrl($workspace, $other))->assertNotFound();
});

test('deploy is permission checked, queued through the gateway, and updated by daemon events', function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()])->save();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $payload = [
        'service' => 'my-app',
        'repo' => 'https://github.com/example/my-app.git',
        'commit' => str_repeat('a', 40),
        'port' => 8080,
        'health_path' => '/health',
        'domain' => 'app.example.com',
    ];

    $this->actingAs($viewer)->postJson(machineUrl($workspace, $machine).'/deploy', $payload)->assertForbidden();
    $this->actingAs($workspace->owner)->postJson(machineUrl($workspace, $machine).'/deploy', $payload)->assertAccepted();

    $job = DaemonJob::query()->where('machine_id', $machine->id)->firstOrFail();
    expect($job->state)->toBe('queued');
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/commands')
        && $request['id'] === $job->id
        && $request['command']['job']['type'] === 'deploy'
        && $request['command']['job']['port'] === 8080);

    $this->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', [
        'envelopes' => [['v' => 1, 'kind' => 'job_update', 'payload' => [
            'job_id' => $job->id, 'state' => 'succeeded', 'phase' => 'verify', 'progress' => 100,
            'log' => ['Health check passed'], 'result' => ['container' => 'serveros-my-app', 'port' => 30000],
        ]]],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();
    expect($job->fresh()->state)->toBe('succeeded')
        ->and($job->fresh()->log)->toBe(['Health check passed']);
});

test('offline machines cannot deploy and bad repository or commit values are rejected', function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake();
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $machine->forceFill(['uid' => (string) Str::uuid()])->save();
    $payload = ['service' => 'app', 'repo' => 'https://github.com/example/app.git', 'commit' => str_repeat('a', 40), 'port' => 80, 'health_path' => '/', 'domain' => null];

    $this->actingAs($workspace->owner)->postJson(machineUrl($workspace, $machine).'/deploy', $payload)->assertStatus(409);
    $machine->forceFill(['last_seen_at' => now()])->save();
    $this->actingAs($workspace->owner)->postJson(machineUrl($workspace, $machine).'/deploy', array_replace($payload, ['repo' => 'https://user:pass@example.com/repo.git']))->assertUnprocessable();
    $this->actingAs($workspace->owner)->postJson(machineUrl($workspace, $machine).'/deploy', array_replace($payload, ['commit' => 'main']))->assertUnprocessable();
    Http::assertNothingSent();
});

test('a removed service leaves the inventory at once, with the rest of its Compose project', function () {
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $container = fn (string $id, ?string $project) => ['key' => "docker:{$id}", 'name' => $id, 'kind' => 'app', 'manager' => 'docker', 'status' => 'running', 'ports' => [], 'confidence' => 90, 'details' => array_filter(['managed' => 'true', 'compose_project' => $project])];
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
            $container('aaa', 'serveros-blog'),
            $container('bbb', 'serveros-blog'),
            $container('ccc', 'serveros-shop'),
            $container('ddd', null),
        ]],
    ])->save();
    $job = DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'service_remove', 'service' => 'docker:aaa', 'state' => 'running', 'command' => []]);

    $this->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', [
        'envelopes' => [['v' => 1, 'kind' => 'job_update', 'payload' => [
            'job_id' => $job->id, 'state' => 'succeeded', 'result' => ['removed' => 2, 'volumes_deleted' => 0],
        ]]],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();

    $machine->refresh();
    expect(array_column($machine->inventory['services'], 'key'))->toBe(['docker:ccc', 'docker:ddd'])
        ->and($machine->services_count)->toBe(2);
});

test('an adopted service is managed at once, not after the rescan', function () {
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
            ['key' => 'systemd:nginx.service', 'name' => 'nginx.service', 'kind' => 'web', 'manager' => 'systemd', 'status' => 'running', 'ports' => [80], 'confidence' => 90, 'details' => ['unit' => 'nginx.service']],
            ['key' => 'systemd:redis.service', 'name' => 'redis.service', 'kind' => 'cache', 'manager' => 'systemd', 'status' => 'running', 'ports' => [], 'confidence' => 90, 'details' => []],
        ]],
    ])->save();
    $job = DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'adopt', 'service' => 'systemd:nginx.service', 'state' => 'running', 'command' => []]);

    $this->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', [
        'envelopes' => [['v' => 1, 'kind' => 'job_update', 'payload' => ['job_id' => $job->id, 'state' => 'succeeded']]],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();

    $services = collect($machine->refresh()->inventory['services'])->keyBy('key');
    expect($services['systemd:nginx.service']['details'])->toBe(['unit' => 'nginx.service', 'managed' => 'true'])
        ->and($services['systemd:redis.service']['details'])->toBe([]);
});

test('a finished deploy asks the daemon to rescan, so the services list catches up', function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()])->save();
    $job = DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'user_id' => $workspace->owner_id, 'kind' => 'deploy', 'service' => 'stats', 'state' => 'running', 'command' => []]);

    $this->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', [
        'envelopes' => [['v' => 1, 'kind' => 'job_update', 'payload' => ['job_id' => $job->id, 'state' => 'succeeded']]],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();

    $rescan = DaemonJob::query()->where('kind', 'discover')->sole();
    expect($rescan->machine_id)->toBe($machine->id);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/commands') && $request['command']['job'] === ['type' => 'discover']);

    // The rescan isn't listed with the deploys.
    $this->actingAs($workspace->owner)->get(machineUrl($workspace, $machine))
        ->assertInertia(fn ($page) => $page->has('jobs', 1)->where('jobs.0.id', $job->id));
});

/** A finished job's update, as the gateway sends it. */
function finishRescanJob(Machine $machine, DaemonJob $job, array $extra = []): void
{
    test()->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', [
        'envelopes' => [['v' => 1, 'kind' => 'job_update', 'payload' => ['job_id' => $job->id, 'state' => 'succeeded']], ...$extra],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();
}

function rescanMachine(): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()])->save();

    return [$workspace, $machine];
}

test('a job with no person behind it, from a workflow say, still gets its rescan', function () {
    [$workspace, $machine] = rescanMachine();
    $job = DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'user_id' => null, 'kind' => 'service_action', 'service' => 'docker:web', 'state' => 'running', 'command' => []]);

    finishRescanJob($machine, $job);

    expect(DaemonJob::query()->where('kind', 'discover')->sole()->user_id)->toBe($workspace->owner_id)
        ->and($machine->fresh()->rescan_wanted_at)->toBeNull();
});

test('a rescan that can’t be sent now goes out when the machine next reports in', function () {
    [$workspace, $machine] = rescanMachine();
    $job = DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'user_id' => $workspace->owner_id, 'kind' => 'deploy', 'service' => 'shop', 'state' => 'running', 'command' => []]);

    // Commands are off for a moment: nothing can be sent.
    config()->set('daemon.commands_enabled', false);
    finishRescanJob($machine, $job);
    expect(DaemonJob::query()->where('kind', 'discover')->count())->toBe(0)
        ->and($machine->fresh()->rescan_wanted_at)->not->toBeNull();

    config()->set('daemon.commands_enabled', true);
    test()->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', [
        'envelopes' => [['v' => 1, 'kind' => 'heartbeat', 'payload' => ['daemon_version' => 'v0.1.1-pre.6']]],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();

    expect(DaemonJob::query()->where('kind', 'discover')->count())->toBe(1)
        ->and($machine->fresh()->rescan_wanted_at)->toBeNull();
});

test('a scan that comes in meanwhile, or an hour passing, settles a wanted rescan', function () {
    [, $machine] = rescanMachine();
    $machine->forceFill(['rescan_wanted_at' => now()])->save();

    test()->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', [
        'envelopes' => [['v' => 1, 'kind' => 'inventory', 'payload' => ['scanned_at' => now()->getTimestamp(), 'services' => [], 'listeners' => []]]],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();
    expect($machine->fresh()->rescan_wanted_at)->toBeNull()
        ->and(DaemonJob::query()->where('kind', 'discover')->count())->toBe(0);

    $machine->forceFill(['rescan_wanted_at' => now()->subHours(2)])->save();
    test()->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', [
        'envelopes' => [['v' => 1, 'kind' => 'heartbeat', 'payload' => ['daemon_version' => 'v0.1.1-pre.6']]],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();
    expect($machine->fresh()->rescan_wanted_at)->toBeNull()
        ->and(DaemonJob::query()->where('kind', 'discover')->count())->toBe(0);
});
