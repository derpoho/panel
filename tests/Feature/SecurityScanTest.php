<?php

use App\Http\Resources\MachineResource;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\SecurityScan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function scannedMachine(array $listeners, array $services = []): Machine
{
    $machine = Machine::factory()->create();
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'facts' => ['os' => 'ubuntu', 'os_version' => '24.04', 'kernel' => '6.8.0', 'cpu_cores' => 4, 'memory_bytes' => 8 * 1024 ** 3, 'docker_version' => null],
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => $listeners, 'services' => $services],
    ])->save();

    return $machine;
}

/** @return array<string, array<string, mixed>> */
function checksByKey(array $report): array
{
    return collect($report['checks'])->keyBy('key')->all();
}

test('databases open to the internet are flagged, local ones are fine', function () {
    $machine = scannedMachine([
        ['proto' => 'tcp', 'address' => '0.0.0.0', 'port' => 22, 'exe' => '/usr/sbin/sshd', 'pid' => 1],
        ['proto' => 'tcp', 'address' => '0.0.0.0', 'port' => 5432, 'exe' => '/usr/lib/postgresql/16/bin/postgres', 'pid' => 2],
        ['proto' => 'tcp', 'address' => '::', 'port' => 5432, 'exe' => '/usr/lib/postgresql/16/bin/postgres', 'pid' => 2],
        ['proto' => 'tcp', 'address' => '127.0.0.1', 'port' => 6379, 'exe' => '/usr/bin/redis-server', 'pid' => 3],
    ]);

    $ports = checksByKey(app(SecurityScan::class)->report($machine, null))['ports'];

    expect($ports['state'])->toBe('issue')
        ->and($ports['summary'])->toBe('2 open to the internet · 1 local only')
        ->and(collect($ports['items'])->firstWhere('tone', 'issue')['text'])->toContain('5432/tcp')->toContain('PostgreSQL');

    $safe = scannedMachine([['proto' => 'tcp', 'address' => '127.0.0.1', 'port' => 5432, 'exe' => 'postgres', 'pid' => 2]]);
    expect(checksByKey(app(SecurityScan::class)->report($safe, null))['ports']['state'])->toBe('ok');
});

test('the updates check follows its job, and the report finishes when it does', function () {
    $machine = scannedMachine([]);
    $job = DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'package_updates', 'state' => 'running', 'log' => [],
    ]);
    $scan = app(SecurityScan::class);

    $report = $scan->report($machine, $job);
    expect(checksByKey($report)['updates']['state'])->toBe('scanning')
        ->and($report['finished'])->toBeFalse();

    $job->forceFill(['state' => 'succeeded', 'result' => ['upgradable' => ['openssl 3.0.13 → 3.0.14', 'curl 8.5 → 8.6'], 'security' => ['openssl 3.0.13 → 3.0.14 (noble-security)']]])->save();
    $report = $scan->report($machine, $job);

    expect(checksByKey($report)['updates'])->toMatchArray(['state' => 'warn', 'summary' => '1 security update waiting'])
        ->and(checksByKey($report)['updates']['items'][0]['text'])->toBe('openssl')
        ->and($report['finished'])->toBeTrue();
});

test('the system check reads Docker from discovery when the daemon gives no version', function () {
    $withDocker = scannedMachine([], [['key' => 'systemd:docker.service', 'name' => 'docker', 'status' => 'running']]);
    $without = scannedMachine([], [['key' => 'systemd:nginx.service', 'name' => 'nginx', 'status' => 'running']]);
    $scan = app(SecurityScan::class);

    expect(collect(checksByKey($scan->report($withDocker, null))['system']['items'])->pluck('text'))->toContain('Docker is running')
        ->and(collect(checksByKey($scan->report($without, null))['system']['items'])->pluck('text'))->toContain('Docker isn’t installed, so deploys won’t run yet');
});

test('starting a scan queues an updates check, and polling reports it', function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
    $workspace = Workspace::factory()->create();
    $machine = scannedMachine([]);
    $machine->forceFill(['workspace_id' => $workspace->id])->save();
    $url = 'https://'.$workspace->host().'/machines/'.$machine->id.'/scan';

    $jobId = $this->actingAs($workspace->owner)->postJson($url)->assertAccepted()->json('jobId');
    expect(DaemonJob::query()->find($jobId)->kind)->toBe('package_updates');

    $this->actingAs($workspace->owner)->getJson($url.'?job='.$jobId)
        ->assertOk()
        ->assertJsonPath('finished', false)
        ->assertJsonPath('checks.1.state', 'scanning');
});

test('a new server walks through its setup stages, and the first scan starts on its own', function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $machine->forceFill(['uid' => (string) Str::uuid()])->save();
    $stage = fn () => (new MachineResource($machine->fresh()))->resolve()['setup'];

    expect($stage())->toBe('connecting');

    $ingest = fn (array $envelope) => $this->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', [
        'envelopes' => [['v' => 1, ...$envelope]],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();

    $ingest(['kind' => 'heartbeat', 'payload' => ['daemon_version' => 'v1']]);
    expect($stage())->toBe('discovering');

    $ingest(['kind' => 'inventory', 'payload' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'services' => [], 'listeners' => []]]);

    // The first inventory queued the security check by itself.
    $scan = DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'package_updates')->sole();
    expect($stage())->toBe('securing');

    $scan->forceFill(['state' => 'succeeded', 'result' => ['upgradable' => [], 'security' => []]])->save();
    expect($stage())->toBeNull()
        ->and((new MachineResource($machine->fresh()))->resolve()['setupFindings'])->toBe(0);

    // A later inventory doesn't start another.
    $ingest(['kind' => 'inventory', 'payload' => ['scanned_at' => now()->getTimestamp() + 1, 'complete' => true, 'services' => [], 'listeners' => []]]);
    expect(DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'package_updates')->count())->toBe(1);
});
