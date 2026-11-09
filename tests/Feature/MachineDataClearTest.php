<?php

use App\Enums\Plan;
use App\Jobs\ClearMachineData;
use App\Mcp\Tools\MachineTools;
use App\Models\Activity;
use App\Models\Backup;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\MachineSample;
use App\Models\Monitor;
use App\Models\ServiceGroup;
use App\Models\ServiceSample;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use App\Support\Logs\LogBuffer;
use App\Support\Logs\LogStore;
use App\Support\Machines\MachineDataEraser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function clearDataUrl(Workspace $workspace, ?Machine $machine = null): string
{
    return 'https://'.$workspace->host().'/machines/'.($machine === null ? '' : $machine->id.'/').'collected-data';
}

/**
 * A connected machine with an adopted service, a deployed-by-ServerOS one,
 * two only discovered, some log lines, and usage history.
 */
function clearDataMachine(Workspace $workspace, string $name = 'web-1', int $lines = 5): Machine
{
    $machine = Machine::factory()->for($workspace)->create(['name' => $name]);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'certificate_serial' => 'serial-'.$name,
        'daemon_version' => 'v0.2.0',
        'connected_at' => now()->subDay(),
        'last_seen_at' => now(),
        'inventory' => ['scanned_at' => now()->subMinutes(5)->getTimestamp(), 'complete' => true, 'listeners' => [['port' => 80], ['port' => 5432]], 'firewall' => ['active' => true], 'services' => [
            ['key' => 'docker:aaa', 'name' => 'shop', 'kind' => 'app', 'status' => 'running', 'details' => ['managed' => 'true']],
            ['key' => 'docker:bbb', 'name' => 'postgres', 'kind' => 'database', 'status' => 'running', 'details' => []],
            ['key' => 'systemd:nginx.service', 'name' => 'nginx', 'kind' => 'web', 'status' => 'running', 'details' => []],
        ]],
        'inventory_scanned_at' => now()->subMinutes(5),
        'services_count' => 3,
        'service_stats' => ['docker:aaa' => ['cpu' => 1], 'docker:bbb' => ['cpu' => 2]],
        'service_labels' => ['docker:bbb' => 'Main database'],
        'hidden_services' => ['systemd:nginx.service'],
        'app_settings' => ['shop' => ['snapshot_before' => false]],
    ])->save();

    clearDataLogs($machine, $lines);
    MachineSample::query()->insert([
        ['machine_id' => $machine->id, 'ts' => now()->subHour()->getTimestamp(), 'samples' => 1, 'cpu' => 10],
        ['machine_id' => $machine->id, 'ts' => now()->subMinutes(30)->getTimestamp(), 'samples' => 1, 'cpu' => 20],
    ]);
    ServiceSample::query()->insert(['machine_id' => $machine->id, 'service' => 'docker:aaa', 'ts' => now()->getTimestamp(), 'cpu' => 1]);

    return $machine->fresh();
}

function clearDataLogs(Machine $machine, int $lines): void
{
    $now = now()->getTimestampMs();
    foreach (array_chunk(range(1, $lines), 500) as $chunk) {
        LogStore::entries($machine->workspace_id)->insert(array_map(fn (int $i) => [
            'machine_id' => $machine->id, 'service_key' => 'docker:aaa', 'service' => 'shop',
            'logged_at' => $now - $i * 60_000, 'level' => 'info', 'stream' => 'stdout', 'message' => "line {$i}",
        ], $chunk));
    }
    // Counts across a few days, so the delete steps through them.
    foreach ([0, 1, 3] as $daysAgo) {
        LogStore::counts($machine->workspace_id)->insert([
            'machine_id' => $machine->id, 'minute' => $now - $now % 60_000 - $daysAgo * 86_400_000, 'service' => 'shop', 'level' => 'info', 'n' => $daysAgo === 0 ? $lines : 1,
        ]);
    }
}

/** @return array<string, mixed> */
function clearDataConnection(Machine $machine): array
{
    return $machine->fresh()->only(['uid', 'certificate_serial', 'daemon_version', 'connected_at', 'disconnected_at', 'last_seen_at']);
}

test('clearing logs deletes only that machine’s lines and counts, and records it', function () {
    $workspace = Workspace::factory()->create();
    $web = clearDataMachine($workspace, 'web-1');
    $db = clearDataMachine($workspace, 'db-1');
    $connection = clearDataConnection($web);

    $this->actingAs($workspace->owner)
        ->post(clearDataUrl($workspace, $web), ['kinds' => ['logs']])
        ->assertRedirect();

    expect(LogStore::entries($workspace)->where('machine_id', $web->id)->count())->toBe(0)
        ->and(LogStore::counts($workspace)->where('machine_id', $web->id)->count())->toBe(0)
        ->and(LogStore::entries($workspace)->where('machine_id', $db->id)->count())->toBe(5)
        ->and(LogStore::counts($workspace)->where('machine_id', $db->id)->count())->toBe(3)
        // Only logs: history and services stay.
        ->and(MachineSample::query()->where('machine_id', $web->id)->count())->toBe(2)
        ->and($web->fresh()->inventory['services'])->toHaveCount(3)
        ->and(clearDataConnection($web))->toEqual($connection);

    $activity = Activity::query()->where('action', 'machine.data_clear')->sole();
    expect($activity->machine_id)->toBe($web->id)
        ->and($activity->subject)->toBe('web-1')
        ->and($activity->meta)->toBe(['logs' => 5]);
    expect(MachineDataEraser::status($web))->toMatchArray(['state' => 'done', 'kinds' => ['logs'], 'counts' => ['logs' => 5]]);
});

test('clearing usage history deletes that machine’s machine and service samples only', function () {
    $workspace = Workspace::factory()->create();
    $web = clearDataMachine($workspace, 'web-1');
    $db = clearDataMachine($workspace, 'db-1');

    $this->actingAs($workspace->owner)
        ->post(clearDataUrl($workspace, $web), ['kinds' => ['metrics']])
        ->assertRedirect();

    expect(MachineSample::query()->where('machine_id', $web->id)->count())->toBe(0)
        ->and(ServiceSample::query()->where('machine_id', $web->id)->count())->toBe(0)
        ->and(MachineSample::query()->where('machine_id', $db->id)->count())->toBe(2)
        ->and(ServiceSample::query()->where('machine_id', $db->id)->count())->toBe(1)
        ->and(LogStore::entries($workspace)->where('machine_id', $web->id)->count())->toBe(5)
        ->and(Activity::query()->where('action', 'machine.data_clear')->sole()->meta)->toBe(['metrics' => 3]);
});

test('clearing services forgets what discovery found but keeps what people set up', function () {
    $workspace = Workspace::factory()->create();
    $web = clearDataMachine($workspace, 'web-1');
    $db = clearDataMachine($workspace, 'db-1');
    $group = ServiceGroup::query()->forceCreate(['machine_id' => $web->id, 'name' => 'Data', 'services' => ['docker:bbb']]);
    $monitor = Monitor::query()->forceCreate(['workspace_id' => $workspace->id, 'machine_id' => $web->id, 'name' => 'site', 'type' => 'http', 'url' => 'https://example.com', 'interval_seconds' => 60, 'timeout_seconds' => 10]);
    $backup = Backup::query()->forceCreate(['machine_id' => $web->id, 'service' => 'docker:aaa', 'service_name' => 'shop', 'trigger' => 'manual', 'state' => 'succeeded']);
    $deploy = DaemonJob::query()->forceCreate(['id' => (string) Str::uuid(), 'machine_id' => $web->id, 'kind' => 'deploy', 'state' => 'succeeded']);
    $connection = clearDataConnection($web);

    $this->actingAs($workspace->owner)
        ->post(clearDataUrl($workspace, $web), ['kinds' => ['services']])
        ->assertRedirect();

    $web->refresh();
    expect(array_column($web->inventory['services'], 'key'))->toBe(['docker:aaa'])
        ->and($web->inventory['listeners'])->toBe([])
        ->and($web->inventory)->not->toHaveKey('firewall')
        ->and($web->inventory_scanned_at)->toBeNull()
        ->and($web->services_count)->toBe(1)
        ->and($web->service_stats)->toBe(['docker:aaa' => ['cpu' => 1]])
        // What people gave services, waiting for them to come back.
        ->and($web->service_labels)->toBe(['docker:bbb' => 'Main database'])
        ->and($web->hidden_services)->toBe(['systemd:nginx.service'])
        ->and($web->app_settings)->toBe(['shop' => ['snapshot_before' => false]])
        ->and($group->fresh())->not->toBeNull()
        ->and($monitor->fresh())->not->toBeNull()
        ->and($backup->fresh())->not->toBeNull()
        ->and($deploy->fresh())->not->toBeNull()
        // Sending work to machines is off here: the scan waits for it to report in.
        ->and($web->rescan_wanted_at)->not->toBeNull()
        ->and(clearDataConnection($web))->toEqual($connection)
        ->and($db->fresh()->inventory['services'])->toHaveCount(3)
        ->and(Activity::query()->where('action', 'machine.data_clear')->sole()->meta)->toBe(['services' => 2]);
});

test('a version of an app ServerOS deployed counts as set up, even without the managed label', function () {
    $workspace = Workspace::factory()->create();
    $machine = clearDataMachine($workspace);
    DaemonJob::query()->forceCreate([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'deploy', 'state' => 'succeeded', 'service' => 'blog',
        'command' => ['job' => ['repo' => 'https://github.com/acme/blog', 'commit' => 'aaaaaaaaaaaa1111']],
    ]);
    $machine->forceFill(['inventory' => [...$machine->inventory, 'services' => [
        ...$machine->inventory['services'],
        ['key' => 'docker:ccc', 'name' => 'serveros-blog-aaaaaaaaaaaa', 'kind' => 'app', 'status' => 'running', 'details' => []],
    ]]])->save();

    app(MachineDataEraser::class)->clear(Machine::query()->find($machine->id), ['services']);

    expect(array_column($machine->fresh()->inventory['services'], 'key'))->toBe(['docker:aaa', 'docker:ccc']);
});

test('the machine stays connected: no disconnect and only a rescan is sent', function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = clearDataMachine($workspace);
    $connection = clearDataConnection($machine);

    $this->actingAs($workspace->owner)
        ->post(clearDataUrl($workspace, $machine), ['kinds' => ['logs', 'metrics', 'services'], 'confirm' => 'web-1'])
        ->assertRedirect();

    expect(clearDataConnection($machine))->toEqual($connection)
        ->and(DaemonJob::query()->where('machine_id', $machine->id)->pluck('kind')->all())->toBe(['discover'])
        ->and($machine->fresh()->rescan_wanted_at)->toBeNull();
    // Commands go after the gateway's usual "managed mode" reconfigure;
    // nothing else is sent to the connection.
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/control') && ($request['control']['type'] ?? null) !== 'reconfigure');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'disconnect') || str_contains($request->url(), 'certificate'));
});

test('a workspace with its own log store is cleared there, buffered lines too', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();
    ownLogStore($workspace);
    $web = clearDataMachine($workspace, 'web-1');
    $db = clearDataMachine($workspace, 'db-1');
    $row = fn (Machine $m) => ['machine_id' => $m->id, 'service_key' => 'docker:aaa', 'service' => 'shop', 'logged_at' => 1, 'level' => 'info', 'stream' => 'stdout', 'message' => 'waiting'];
    LogBuffer::push($workspace->id, [$row($web), $row($db), $row($web)]);

    expect(LogStore::isExternal($workspace))->toBeTrue()
        ->and(LogStore::entries($workspace)->where('machine_id', $web->id)->count())->toBe(5);

    $this->actingAs($workspace->owner)
        ->post(clearDataUrl($workspace, $web), ['kinds' => ['logs']])
        ->assertRedirect();

    expect(LogStore::entries($workspace)->where('machine_id', $web->id)->count())->toBe(0)
        ->and(LogStore::counts($workspace)->where('machine_id', $web->id)->count())->toBe(0)
        ->and(LogStore::entries($workspace)->where('machine_id', $db->id)->count())->toBe(5)
        ->and(LogBuffer::size($workspace->id))->toBe(1)
        ->and(LogBuffer::take($workspace->id, 10)[0]['machine_id'])->toBe($db->id)
        // 5 stored and 2 waiting.
        ->and(Activity::query()->where('action', 'machine.data_clear')->sole()->meta)->toBe(['logs' => 7]);
});

test('logs are deleted in chunks, a few thousand lines at a time', function () {
    $workspace = Workspace::factory()->create();
    $machine = clearDataMachine($workspace, 'web-1', 4500);
    $other = clearDataMachine($workspace, 'db-1', 10);

    expect(app(MachineDataEraser::class)->estimate($machine, ['logs']))->toBe(['logs' => 4502])
        ->and(MachineDataEraser::CHUNK)->toBeLessThan(4500);

    DB::enableQueryLog();
    expect(app(MachineDataEraser::class)->clear($machine, ['logs']))->toBe(['logs' => 4500]);
    $deletes = collect(DB::getQueryLog())->filter(fn (array $q) => str_starts_with($q['query'], 'delete from "log_entries"'))->count();
    DB::disableQueryLog();

    expect($deletes)->toBe(3)
        ->and(LogStore::entries($workspace)->where('machine_id', $machine->id)->count())->toBe(0)
        ->and(LogStore::entries($workspace)->where('machine_id', $other->id)->count())->toBe(10);
});

test('a clear past the limit runs in the background and is recorded as going', function () {
    Queue::fake();
    $workspace = Workspace::factory()->create();
    $machine = clearDataMachine($workspace, 'web-1', MachineDataEraser::SYNC_LIMIT + 100);

    $this->actingAs($workspace->owner)
        ->post(clearDataUrl($workspace, $machine), ['kinds' => ['logs']])
        ->assertRedirect();

    Queue::assertPushed(ClearMachineData::class, fn (ClearMachineData $job) => $job->machineId === $machine->id && $job->kinds === ['logs'] && $job->userId === $workspace->owner->id);
    expect(MachineDataEraser::status($machine)['state'])->toBe('queued')
        ->and(Activity::query()->where('action', 'machine.data_clear')->sole()->meta)->toBe(['logs' => MachineDataEraser::SYNC_LIMIT + 102, 'queued' => true])
        ->and(LogStore::entries($workspace)->where('machine_id', $machine->id)->count())->toBe(MachineDataEraser::SYNC_LIMIT + 100);
});

test('the job clears, reports its state, and is safe to run again', function () {
    $workspace = Workspace::factory()->create();
    $machine = clearDataMachine($workspace, 'web-1', 2500);

    (new ClearMachineData($machine->id, ['logs', 'metrics'], $workspace->owner->id))->handle(app(MachineDataEraser::class));
    expect(MachineDataEraser::status($machine))->toMatchArray(['state' => 'done', 'counts' => ['logs' => 2500, 'metrics' => 3]])
        ->and(LogStore::entries($workspace)->where('machine_id', $machine->id)->count())->toBe(0);

    (new ClearMachineData($machine->id, ['logs'], null))->handle(app(MachineDataEraser::class));
    expect(MachineDataEraser::status($machine))->toMatchArray(['state' => 'done', 'counts' => ['logs' => 0]]);
});

test('clearing everything needs the machine’s name typed', function () {
    $workspace = Workspace::factory()->create();
    $machine = clearDataMachine($workspace);

    $this->actingAs($workspace->owner)
        ->post(clearDataUrl($workspace, $machine), ['kinds' => ['logs', 'metrics', 'services'], 'confirm' => 'web'])
        ->assertSessionHasErrors('confirm');

    expect(LogStore::entries($workspace)->where('machine_id', $machine->id)->count())->toBe(5)
        ->and(Activity::query()->where('action', 'machine.data_clear')->exists())->toBeFalse();

    $this->actingAs($workspace->owner)
        ->post(clearDataUrl($workspace, $machine), ['kinds' => ['logs', 'metrics', 'services'], 'confirm' => 'web-1'])
        ->assertSessionHasNoErrors();

    expect(Activity::query()->where('action', 'machine.data_clear')->sole()->meta)->toBe(['logs' => 5, 'metrics' => 3, 'services' => 2]);
});

test('viewers can’t clear, and nobody can clear another workspace’s machine', function () {
    $workspace = Workspace::factory()->create();
    $machine = clearDataMachine($workspace);
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $other = clearDataMachine(Workspace::factory()->create(), 'theirs');

    $this->actingAs($viewer)->post(clearDataUrl($workspace, $machine), ['kinds' => ['logs']])->assertForbidden();
    $this->actingAs($viewer)->post(clearDataUrl($workspace), ['machines' => [$machine->id], 'kinds' => ['logs']])->assertForbidden();
    $this->actingAs($workspace->owner)->post(clearDataUrl($workspace, $other), ['kinds' => ['logs']])->assertNotFound();
    $this->actingAs($workspace->owner)->post(clearDataUrl($workspace), ['machines' => [$other->id], 'kinds' => ['logs']])->assertSessionHasErrors('machines.0');

    expect(LogStore::entries($workspace)->where('machine_id', $machine->id)->count())->toBe(5)
        ->and(LogStore::entries($other->workspace_id)->where('machine_id', $other->id)->count())->toBe(5);
});

test('machines picked on the Machines page are cleared together, one activity entry each', function () {
    $workspace = Workspace::factory()->create();
    $web = clearDataMachine($workspace, 'web-1');
    $db = clearDataMachine($workspace, 'db-1');
    $left = clearDataMachine($workspace, 'cache-1');

    $this->actingAs($workspace->owner)
        ->post(clearDataUrl($workspace), ['machines' => [$web->id, $db->id], 'kinds' => ['logs', 'metrics']])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    foreach ([$web, $db] as $machine) {
        expect(LogStore::entries($workspace)->where('machine_id', $machine->id)->count())->toBe(0)
            ->and(MachineSample::query()->where('machine_id', $machine->id)->count())->toBe(0)
            ->and($machine->fresh()->inventory['services'])->toHaveCount(3);
    }
    expect(LogStore::entries($workspace)->where('machine_id', $left->id)->count())->toBe(5)
        ->and(MachineSample::query()->where('machine_id', $left->id)->count())->toBe(2)
        ->and(Activity::query()->where('action', 'machine.data_clear')->orderBy('subject')->pluck('subject')->all())->toBe(['db-1', 'web-1']);
});

test('the settings page counts what the machine has sent once asked', function () {
    $workspace = Workspace::factory()->create();
    $machine = clearDataMachine($workspace);

    $url = 'https://'.$workspace->host().'/machines/'.$machine->id.'/settings';
    $page = $this->actingAs($workspace->owner)->get($url)->viewData('page');
    expect($page['props'])->not->toHaveKey('collectedData');

    $this->actingAs($workspace->owner)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $page['version'], 'X-Inertia-Partial-Component' => 'workspace/MachineSettings', 'X-Inertia-Partial-Data' => 'collectedData'])
        ->get($url)
        ->assertOk()
        ->assertJsonPath('props.collectedData.logs.lines', 7)
        ->assertJsonPath('props.collectedData.metrics.readings', 3)
        ->assertJsonPath('props.collectedData.services.discovered', 2)
        ->assertJsonPath('props.collectedData.services.kept', 1);
});

test('the activity line says what went', function () {
    $workspace = Workspace::factory()->create();
    $machine = clearDataMachine($workspace);
    $this->actingAs($workspace->owner)->post(clearDataUrl($workspace, $machine), ['kinds' => ['logs']]);

    $line = ActivityLine::describe(Activity::query()->where('action', 'machine.data_clear')->sole(), false);
    expect($line['verb'])->toBe('cleared logs on')
        ->and($line['after'])->toBe('5 log lines')
        ->and(ActivityLine::compact(1_234_567))->toBe('1.2M')
        ->and(ActivityLine::compact(14_200))->toBe('14,200');
});

test('a future “turn off” switch can ask what a feature collected', function () {
    expect(MachineDataEraser::forFeature('logs'))->toBe(['logs'])
        ->and(MachineDataEraser::forFeature('discovery'))->toBe(['services'])
        ->and(MachineDataEraser::forFeature('metrics'))->toBe(['metrics']);

    $workspace = Workspace::factory()->create();
    $machine = clearDataMachine($workspace);
    app(MachineDataEraser::class)->clear($machine, MachineDataEraser::forFeature('discovery'), rescan: false);

    expect($machine->fresh()->rescan_wanted_at)->toBeNull()
        ->and($machine->fresh()->inventory['services'])->toHaveCount(1);
});

test('clear_machine_data needs the machine’s name to confirm, then clears', function () {
    config()->set('mcp.groups', [MachineTools::class]);
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create(['name' => 'Acme']);
    $machine = clearDataMachine($workspace);
    $key = mcpKey($workspace);

    $asked = mcpCall($workspace, $key, 'clear_machine_data', ['machine' => 'web-1', 'kinds' => ['logs'], 'confirm' => '']);
    expect($asked['error'])->toBeTrue()
        ->and($asked['text'])->toContain('7 log lines')->toContain('“web-1”');
    $wrong = mcpCall($workspace, $key, 'clear_machine_data', ['machine' => 'web-1', 'kinds' => ['logs'], 'confirm' => 'web-2']);
    expect($wrong['error'])->toBeTrue()
        ->and(LogStore::entries($workspace)->where('machine_id', $machine->id)->count())->toBe(5);

    $done = mcpCall($workspace, $key, 'clear_machine_data', ['machine' => 'web-1', 'kinds' => ['logs', 'services'], 'confirm' => 'web-1']);
    expect($done['error'])->toBeFalse()
        ->and($done['data']['cleared'])->toBe(['logs' => 5, 'services' => 2])
        ->and($done['data']['note'])->toContain('stays connected')
        ->and(LogStore::entries($workspace)->where('machine_id', $machine->id)->count())->toBe(0);
    $activity = Activity::query()->where('action', 'machine.data_clear')->sole();
    expect($activity->source)->toBe('mcp');
});
