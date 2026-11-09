<?php

use App\Enums\MachineEnvironment;
use App\Enums\Plan;
use App\Jobs\CheckMachineUpdates;
use App\Mcp\Scopes;
use App\Mcp\Tools\MachineTools;
use App\Models\Activity;
use App\Models\Backup;
use App\Models\BackupDestination;
use App\Models\DaemonEnrolment;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\MachineGroup;
use App\Models\MachinePackageUpdate;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceStatusPage;
use App\Support\Machines\PackageUpdates;
use App\Support\Monitors\HostResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(fn () => config()->set('mcp.groups', [MachineTools::class]));

/** Turn on server commands with a gateway that accepts everything. */
function machineToolsGateway(): void
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
}

/** An online machine with a scan. */
function machineToolsMachine(Workspace $workspace, string $name = 'web-1', array $attributes = []): Machine
{
    $machine = Machine::factory()->for($workspace)->create(['name' => $name, ...$attributes]);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'daemon_version' => 'v0.2.0',
        'inventory' => ['scanned_at' => now()->subMinutes(5)->getTimestamp(), 'complete' => true, 'listeners' => [['port' => 80]], 'services' => [
            ['key' => 'docker:aaa', 'name' => 'shop', 'kind' => 'app', 'manager' => 'docker', 'status' => 'running', 'ports' => [], 'details' => ['managed' => 'true']],
            ['key' => 'systemd:nginx.service', 'name' => 'nginx', 'kind' => 'web', 'manager' => 'systemd', 'status' => 'running', 'ports' => [80], 'details' => []],
            ['key' => 'docker:bbb', 'name' => 'worker', 'kind' => 'app', 'manager' => 'docker', 'status' => 'exited', 'ports' => [], 'details' => []],
        ]],
    ])->save();

    return $machine;
}

/** @return array{0: Workspace, 1: Machine, 2: string} */
function machineToolsSetup(?Plan $plan = Plan::Pro): array
{
    $workspace = Workspace::factory()->onPlan($plan)->create(['name' => 'Acme']);
    $machine = machineToolsMachine($workspace);

    return [$workspace, $machine, mcpKey($workspace)];
}

function machineToolsViewer(Workspace $workspace): string
{
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    return mcpKey($workspace, $viewer);
}

/** The last thing sent to the gateway. */
function machineToolsSent(): array
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn ($r) => str_ends_with($r->url(), '/commands'))->map(fn ($r) => $r->data())->values()->all();
}

const MACHINE_TOOLS_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

test('tools are listed by what the connection and role allow', function () {
    [$workspace] = machineToolsSetup();

    $all = collect(mcpRequest($workspace, mcpKey($workspace), 'tools/list')->json('result.tools'))->keyBy('name');
    expect($all->keys()->all())->toEqualCanonicalizing([
        'list_machines', 'get_machine', 'update_machine', 'set_machine_icon', 'remove_machine_icon',
        'list_machine_groups', 'create_machine_group', 'update_machine_group', 'delete_machine_group',
        'list_machine_policies', 'create_machine_policy', 'update_machine_policy',
        'start_maintenance', 'end_maintenance', 'rescan_machine', 'check_updates', 'get_updates_result',
        'install_updates', 'install_packages', 'reboot_machine', 'add_machine', 'get_machine_connection_status', 'remove_machine',
        'get_firewall', 'open_port', 'close_port', 'get_disk_usage', 'clear_machine_data',
        'list_package_updates', 'create_package_rollout', 'get_package_rollout',
    ]);
    foreach (['remove_machine', 'reboot_machine', 'delete_machine_group', 'install_updates', 'close_port', 'clear_machine_data', 'create_package_rollout'] as $name) {
        expect($all[$name]['annotations']['destructiveHint'])->toBeTrue()
            ->and($all[$name]['inputSchema']['required'])->toContain('confirm');
    }
    // Installing new packages removes nothing, but still asks.
    expect($all['install_packages']['annotations']['destructiveHint'])->toBeFalse()
        ->and($all['install_packages']['inputSchema']['required'])->toContain('confirm');
    expect($all['set_machine_icon']['annotations']['openWorldHint'])->toBeTrue()
        ->and($all['list_machines']['annotations']['readOnlyHint'])->toBeTrue()
        ->and($all['update_machine']['annotations']['readOnlyHint'])->toBeFalse();

    // Read-only: the reading tools, including those an owner's role allows.
    // Checking for updates runs the package manager on the server, so it
    // needs a connection that may make changes.
    $read = collect(mcpRequest($workspace, mcpKey($workspace, scopes: [Scopes::READ]), 'tools/list')->json('result.tools'))->pluck('name');
    expect($read->all())->toEqualCanonicalizing(['list_machines', 'get_machine', 'list_machine_groups', 'list_machine_policies', 'rescan_machine', 'get_updates_result', 'get_machine_connection_status', 'get_firewall', 'list_package_updates', 'get_package_rollout'])
        ->and($all['check_updates']['annotations']['readOnlyHint'])->toBeFalse();

    // A viewer: reading only, and not what the dashboard keeps to managers.
    $viewer = collect(mcpRequest($workspace, machineToolsViewer($workspace), 'tools/list')->json('result.tools'))->pluck('name');
    expect($viewer->all())->toEqualCanonicalizing(['list_machines', 'get_machine', 'list_machine_groups', 'list_machine_policies', 'rescan_machine', 'get_updates_result', 'get_firewall', 'list_package_updates', 'get_package_rollout']);
});

test('list_machines filters, sorts and pages like the machines page', function () {
    [$workspace, $web, $key] = machineToolsSetup();
    $web->forceFill(['cpu_percent' => 10, 'ip_address' => '10.0.0.1', 'provider' => 'Hetzner'])->save();
    $db = machineToolsMachine($workspace, 'db-1', ['environment' => MachineEnvironment::Staging, 'cpu_percent' => 80, 'provider' => 'DigitalOcean']);
    $old = Machine::factory()->for($workspace)->create(['name' => 'old-box', 'cpu_percent' => 40, 'provider' => 'Vultr']);
    $old->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()->subHour()])->save();
    $group = MachineGroup::query()->forceCreate(['workspace_id' => $workspace->id, 'name' => 'EU']);
    $db->forceFill(['machine_group_id' => $group->id, 'maintenance_until' => now()->addHour(), 'maintenance_reason' => 'Upgrade'])->save();
    machineToolsMachine(Workspace::factory()->create(), 'theirs');

    $all = mcpCall($workspace, $key, 'list_machines');
    expect($all['error'])->toBeFalse()
        ->and($all['data']['total'])->toBe(3)
        ->and(collect($all['data']['machines'])->pluck('name')->all())->toBe(['db-1', 'old-box', 'web-1']);
    $row = collect($all['data']['machines'])->firstWhere('name', 'db-1');
    expect($row)->toMatchArray(['environment' => 'staging', 'status' => 'online', 'group' => ['id' => $group->id, 'name' => 'EU'], 'services' => 3])
        ->and($row['maintenance']['reason'])->toBe('Upgrade')
        ->and($row['url'])->toBe('https://'.$workspace->host().'/machines/'.$db->id);

    expect(collect(mcpCall($workspace, $key, 'list_machines', ['environment' => 'staging'])['data']['machines'])->pluck('name')->all())->toBe(['db-1'])
        ->and(collect(mcpCall($workspace, $key, 'list_machines', ['status' => 'offline'])['data']['machines'])->pluck('name')->all())->toBe(['old-box'])
        ->and(collect(mcpCall($workspace, $key, 'list_machines', ['status' => 'maintenance'])['data']['machines'])->pluck('name')->all())->toBe(['db-1'])
        ->and(collect(mcpCall($workspace, $key, 'list_machines', ['group' => 'eu'])['data']['machines'])->pluck('name')->all())->toBe(['db-1'])
        ->and(collect(mcpCall($workspace, $key, 'list_machines', ['group' => 'none'])['data']['machines'])->pluck('name')->all())->toBe(['old-box', 'web-1'])
        ->and(collect(mcpCall($workspace, $key, 'list_machines', ['query' => '10.0.0'])['data']['machines'])->pluck('name')->all())->toBe(['web-1'])
        ->and(collect(mcpCall($workspace, $key, 'list_machines', ['query' => 'digital'])['data']['machines'])->pluck('name')->all())->toBe(['db-1'])
        ->and(collect(mcpCall($workspace, $key, 'list_machines', ['provider' => 'hetzner'])['data']['machines'])->pluck('name')->all())->toBe(['web-1'])
        ->and(collect(mcpCall($workspace, $key, 'list_machines', ['provider' => 'Digital'])['data']['machines'])->pluck('name')->all())->toBe([])
        ->and(collect(mcpCall($workspace, $key, 'list_machines', ['sort' => 'cpu'])['data']['machines'])->pluck('name')->all())->toBe(['db-1', 'old-box', 'web-1']);

    $page = mcpCall($workspace, $key, 'list_machines', ['limit' => 2]);
    expect($page['data'])->toMatchArray(['total' => 3, 'count' => 2, 'more' => true]);
    $next = mcpCall($workspace, $key, 'list_machines', ['limit' => 2, 'offset' => 2]);
    expect($next['data'])->toMatchArray(['count' => 1, 'more' => false]);

    expect(mcpCall($workspace, $key, 'list_machines', ['group' => 'Nope'])['text'])->toContain('No machine group “Nope”')
        ->and(mcpCall($workspace, $key, 'list_machines', ['status' => 'sleeping'])['text'])->toContain('Invalid arguments')
        ->and(mcpCall($workspace, $key, 'list_machines', ['limit' => 1000])['error'])->toBeTrue();
});

test('get_machine gives the whole picture, without secrets', function () {
    [$workspace, $machine, $key] = machineToolsSetup();
    $destination = new BackupDestination(['name' => 'R2', 'type' => 's3', 'settings' => [
        'endpoint' => 'acc.r2.cloudflarestorage.com', 'region' => 'auto', 'bucket' => 'worlds',
        'access_key' => 'AKIA-VISIBLE-NOT', 'secret_key' => 'shh-secret', 'path_style' => true, 'prefix' => 'serveros',
    ]]);
    $destination->workspace_id = $workspace->id;
    $destination->save();
    $machine->forceFill([
        'backup_destination_id' => $destination->id,
        'backup_schedule' => 'daily',
        'memory_used_mb' => 2048,
        'disk_percent' => 50,
        'disks' => [['mount' => '/', 'used' => 50 * 1024 ** 3, 'total' => 100 * 1024 ** 3]],
        'facts' => [
            'hostname' => 'box-1', 'os' => 'ubuntu', 'os_version' => '24.04', 'kernel' => '6.8.0', 'arch' => 'x86_64',
            'cpu_cores' => 4, 'cpu_model' => 'AMD EPYC', 'memory_bytes' => 8 * 1024 ** 3, 'docker_version' => '27.1',
            'boot_ts' => now()->subDay()->getTimestamp(),
            'hosting' => ['provider' => 'Hetzner', 'region' => 'eu-central', 'zone' => 'hel1-dc2'],
            'disks' => [['mount' => '/', 'device' => '/dev/sda1', 'fs_type' => 'ext4', 'total_bytes' => 100 * 1024 ** 3]],
            'interfaces' => [['name' => 'eth0', 'addresses' => ['203.0.113.5']]],
        ],
    ])->save();
    $backup = new Backup(['backup_destination_id' => $destination->id, 'service' => 'docker:aaa', 'service_name' => 'shop', 'trigger' => 'scheduled', 'state' => 'succeeded']);
    $backup->machine_id = $machine->id;
    $backup->save();
    $page = StatusPage::draftFor($machine);
    $page->fill(['enabled' => true])->save();
    foreach ([['discover', 'succeeded', null], ['deploy', 'failed', null], ['package_updates', 'succeeded', [
        'manager' => 'apt', 'upgradable' => ['openssl/noble-updates,noble-security 3.0.13-0ubuntu3.5 amd64 [upgradable from: 3.0.13-0ubuntu3.4]', 'curl/noble-updates 8.5.0-2ubuntu10.6 amd64 [upgradable from: 8.5.0-2ubuntu10.5]'],
        'security' => ['openssl/noble-updates,noble-security 3.0.13-0ubuntu3.5 amd64 [upgradable from: 3.0.13-0ubuntu3.4]'], 'reboot_required' => true, 'applied' => false,
    ]]] as [$kind, $state, $result]) {
        DaemonJob::query()->forceCreate(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => $kind, 'state' => $state, 'result' => $result, 'error' => $state === 'failed' ? ['message' => 'Build failed'] : null]);
    }

    $result = mcpCall($workspace, $key, 'get_machine', ['machine' => 'WEB-1']);
    expect($result['error'])->toBeFalse();
    $data = $result['data'];
    expect($data)->toMatchArray(['id' => $machine->id, 'name' => 'web-1', 'status' => 'online'])
        ->and($data['facts'])->toMatchArray(['hostname' => 'box-1', 'os' => 'ubuntu', 'osVersion' => '24.04', 'cpuCores' => 4, 'memoryTotalMb' => 8192, 'docker' => true, 'dockerVersion' => '27.1'])
        ->and($data['facts']['hosting'])->toMatchArray(['region' => 'eu-central', 'zone' => 'hel1-dc2'])
        ->and($data['facts']['interfaces'][0])->toBe(['name' => 'eth0', 'addresses' => ['203.0.113.5']])
        ->and($data['usage'])->toMatchArray(['memoryUsedMb' => 2048, 'memoryTotalMb' => 8192, 'memoryPercent' => 25, 'diskPercent' => 50])
        ->and($data['usage']['disks'][0])->toMatchArray(['mount' => '/', 'device' => '/dev/sda1', 'fsType' => 'ext4', 'percent' => 50, 'totalGb' => 100.0])
        ->and($data['services'])->toMatchArray(['total' => 3, 'byStatus' => ['running' => 2, 'exited' => 1], 'containers' => 2, 'managed' => 1])
        ->and($data['services']['notRunning'])->toBe([['key' => 'docker:bbb', 'name' => 'worker', 'status' => 'exited']])
        ->and($data['backups'])->toMatchArray(['schedule' => 'daily', 'backupableServices' => 1])
        ->and($data['backups']['destination'])->toMatchArray(['name' => 'R2', 'type' => 's3', 'where' => 's3://worlds/serveros'])
        ->and($data['backups']['lastRun'])->toMatchArray(['service' => 'shop', 'state' => 'succeeded'])
        ->and($data['statusPage']['planAllows'])->toBeTrue()
        ->and($data['statusPage']['own'])->toMatchArray(['slug' => $page->slug, 'enabled' => true, 'live' => true])
        ->and($data['statusPage']['own']['url'])->toEndWith('/status/'.$page->slug)
        ->and($data['updates'])->toMatchArray(['waiting' => 2, 'security' => 1, 'rebootRequired' => true])
        ->and(collect($data['recentJobs'])->pluck('kind')->sort()->values()->all())->toBe(['deploy', 'package_updates'])
        ->and(collect($data['recentJobs'])->firstWhere('kind', 'deploy')['error'])->toBe('Build failed');

    expect($result['text'])->not->toContain('shh-secret')->not->toContain('AKIA-VISIBLE-NOT');

    // Only machines in this workspace.
    $theirs = machineToolsMachine(Workspace::factory()->create(), 'theirs');
    expect(mcpCall($workspace, $key, 'get_machine', ['machine' => (string) $theirs->id]))
        ->error->toBeTrue()
        ->text->toContain('No machine');
    expect(mcpCall($workspace, $key, 'get_machine', ['machine' => 'theirs'])['error'])->toBeTrue()
        ->and(mcpCall($workspace, $key, 'get_machine', ['machine' => ['x']])['text'])->toContain('Invalid arguments');
});

test('update_machine renames and moves environments like settings, recorded via MCP', function () {
    [$workspace, $machine, $key] = machineToolsSetup();

    $result = mcpCall($workspace, $key, 'update_machine', ['machine' => $machine->id, 'name' => '  api-1 ', 'environment' => 'staging']);
    expect($result['error'])->toBeFalse()
        ->and($result['data']['changed'])->toBe(['name' => ['from' => 'web-1', 'to' => 'api-1'], 'environment' => ['from' => 'production', 'to' => 'staging']])
        ->and($machine->fresh())->name->toBe('api-1')->environment->toBe(MachineEnvironment::Staging);

    $rename = Activity::query()->where('action', 'machine.rename')->sole();
    expect($rename->subject)->toBe('api-1')
        ->and($rename->user_id)->toBe($workspace->owner_id)
        ->and($rename->meta)->toMatchArray(['from' => 'web-1', 'via' => 'mcp'])
        ->and(Activity::query()->where('action', 'machine.environment')->sole()->meta)->toMatchArray(['to' => 'staging', 'via' => 'mcp']);

    // Nothing new: nothing recorded.
    expect(mcpCall($workspace, $key, 'update_machine', ['machine' => 'api-1', 'environment' => 'staging'])['data']['changed'])->toBeNull()
        ->and(Activity::query()->count())->toBe(2);

    expect(mcpCall($workspace, $key, 'update_machine', ['machine' => 'api-1'])['text'])->toContain('Give a new name')
        ->and(mcpCall($workspace, $key, 'update_machine', ['machine' => 'api-1', 'name' => '   '])['text'])->toContain('Invalid arguments')
        ->and(mcpCall($workspace, $key, 'update_machine', ['machine' => 'api-1', 'environment' => 'qa'])['text'])->toContain('Invalid arguments')
        ->and(mcpCall($workspace, $key, 'update_machine', ['machine' => 'api-1', 'name' => str_repeat('a', 256)])['error'])->toBeTrue();

    // Viewers and read-only connections can't.
    expect(mcpCall($workspace, machineToolsViewer($workspace), 'update_machine', ['machine' => 'api-1', 'name' => 'x'])['text'])->toContain('doesn’t allow that')
        ->and(mcpCall($workspace, mcpKey($workspace, scopes: [Scopes::READ]), 'update_machine', ['machine' => 'api-1', 'name' => 'x'])['text'])->toContain('read-only')
        ->and($machine->fresh()->name)->toBe('api-1');
});

test('set_machine_icon fetches a public image and stores it like an upload; remove_machine_icon clears it', function () {
    Storage::fake('public');
    app()->instance(HostResolver::class, new class extends HostResolver
    {
        public function resolve(string $host): array
        {
            return str_contains($host, 'internal') ? [] : ['93.184.215.14'];
        }
    });
    Http::fake([
        'https://cdn.example.com/logo.png' => Http::response(base64_decode(MACHINE_TOOLS_PNG), 200, ['Content-Type' => 'image/png']),
        'https://cdn.example.com/logo.svg' => Http::response('<svg xmlns="http://www.w3.org/2000/svg"></svg>', 200, ['Content-Type' => 'image/svg+xml']),
    ]);
    [$workspace, $machine, $key] = machineToolsSetup();
    $machine->forceFill(['icon_path' => 'machine-icons/old.png'])->save();
    Storage::disk('public')->put('machine-icons/old.png', 'old');

    $result = mcpCall($workspace, $key, 'set_machine_icon', ['machine' => 'web-1', 'url' => 'https://cdn.example.com/logo.png']);
    expect($result['error'])->toBeFalse();
    $path = $machine->fresh()->icon_path;
    expect($path)->toStartWith('machine-icons/')->not->toBe('machine-icons/old.png')
        ->and($result['data']['icon'])->toBe(Storage::disk('public')->url($path));
    Storage::disk('public')->assertExists($path);
    Storage::disk('public')->assertMissing('machine-icons/old.png');

    expect(mcpCall($workspace, $key, 'set_machine_icon', ['machine' => 'web-1', 'url' => 'http://cdn.example.com/logo.png'])['error'])->toBeTrue()
        ->and(mcpCall($workspace, $key, 'set_machine_icon', ['machine' => 'web-1', 'url' => 'https://cdn.example.com/logo.svg'])['text'])->toContain('isn’t a PNG')
        ->and(mcpCall($workspace, $key, 'set_machine_icon', ['machine' => 'web-1', 'url' => 'https://internal.example.com/logo.png'])['text'])->toContain('private network')
        ->and($machine->fresh()->icon_path)->toBe($path);

    expect(mcpCall($workspace, $key, 'remove_machine_icon', ['machine' => 'web-1'])['data']['removed'])->toBeTrue();
    Storage::disk('public')->assertMissing($path);
    expect($machine->fresh()->icon_path)->toBeNull()
        ->and(mcpCall($workspace, $key, 'remove_machine_icon', ['machine' => 'web-1'])['data']['removed'])->toBeFalse();
});

test('machine groups are created, changed and deleted like the machines page', function () {
    [$workspace, $web, $key] = machineToolsSetup();
    $db = machineToolsMachine($workspace, 'db-1');
    $cache = machineToolsMachine($workspace, 'cache-1');
    $old = MachineGroup::query()->forceCreate(['workspace_id' => $workspace->id, 'name' => 'Old']);
    $cache->forceFill(['machine_group_id' => $old->id])->save();

    $created = mcpCall($workspace, $key, 'create_machine_group', ['name' => ' EU ', 'machines' => ['web-1', (string) $cache->id, $web->id]]);
    expect($created['error'])->toBeFalse()
        ->and($created['data']['group']['name'])->toBe('EU')
        ->and(collect($created['data']['group']['machines'])->pluck('name')->all())->toBe(['cache-1', 'web-1'])
        ->and($created['data']['moved'])->toBe([['id' => $cache->id, 'name' => 'cache-1', 'fromGroup' => 'Old']]);
    $eu = MachineGroup::query()->where('name', 'EU')->sole();
    expect($cache->fresh()->machine_group_id)->toBe($eu->id);

    $list = mcpCall($workspace, $key, 'list_machine_groups')['data'];
    expect(collect($list['groups'])->pluck('name')->all())->toBe(['EU', 'Old'])
        ->and($list['ungroupedMachines'])->toBe(1);

    $changed = mcpCall($workspace, $key, 'update_machine_group', ['group' => 'eu', 'name' => 'Europe', 'add' => ['db-1'], 'remove' => ['web-1']]);
    expect($changed['error'])->toBeFalse()
        ->and($changed['data']['group']['name'])->toBe('Europe')
        ->and(collect($changed['data']['group']['machines'])->pluck('name')->all())->toBe(['cache-1', 'db-1'])
        ->and($changed['data']['removed'])->toBe(1)
        ->and($web->fresh()->machine_group_id)->toBeNull();

    $replaced = mcpCall($workspace, $key, 'update_machine_group', ['group' => (string) $eu->id, 'machines' => []]);
    expect($replaced['data']['group']['machines'])->toBe([])
        ->and(Machine::query()->whereNotNull('machine_group_id')->count())->toBe(0);
    expect(mcpCall($workspace, $key, 'update_machine_group', ['group' => 'Europe'])['text'])->toContain('nothing to change');
    // Each change is in Activity, as the machines page records them; the same members again records nothing.
    mcpCall($workspace, $key, 'update_machine_group', ['group' => 'Europe', 'machines' => []]);
    expect(Activity::query()->whereIn('action', ['machine_group.create', 'machine_group.update'])->orderBy('id')->get()->map(fn (Activity $a) => [$a->action, $a->subject, $a->meta['via'] ?? null])->all())
        ->toBe([['machine_group.create', 'EU', 'mcp'], ['machine_group.update', 'Europe', 'mcp'], ['machine_group.update', 'Europe', 'mcp']]);

    // Other workspaces' machines and groups aren't reachable.
    $theirs = machineToolsMachine(Workspace::factory()->create(), 'theirs');
    $foreign = MachineGroup::query()->forceCreate(['workspace_id' => $theirs->workspace_id, 'name' => 'Theirs']);
    expect(mcpCall($workspace, $key, 'create_machine_group', ['name' => 'X', 'machines' => [(string) $theirs->id]])['text'])->toContain('No machine')
        ->and(mcpCall($workspace, $key, 'update_machine_group', ['group' => (string) $foreign->id, 'name' => 'Mine'])['text'])->toContain('No machine group')
        ->and(mcpCall($workspace, $key, 'delete_machine_group', ['group' => (string) $foreign->id, 'confirm' => 'Theirs'])['error'])->toBeTrue()
        ->and($foreign->fresh()->name)->toBe('Theirs')
        ->and(MachineGroup::query()->where('name', 'X')->exists())->toBeFalse();

    // Deleting needs the name typed.
    $db->forceFill(['machine_group_id' => $eu->id])->save();
    expect(mcpCall($workspace, $key, 'delete_machine_group', ['group' => 'Europe'])['text'])->toContain('confirm')
        ->and(mcpCall($workspace, $key, 'delete_machine_group', ['group' => 'Europe', 'confirm' => 'europe'])['text'])->toContain('doesn’t match')
        ->and($eu->fresh())->not->toBeNull();
    $deleted = mcpCall($workspace, $key, 'delete_machine_group', ['group' => 'Europe', 'confirm' => 'Europe']);
    expect($deleted['data'])->toBe(['deleted' => ['id' => $eu->id, 'name' => 'Europe'], 'machinesUngrouped' => 1])
        ->and(MachineGroup::query()->find($eu->id))->toBeNull()
        ->and($db->fresh()->machine_group_id)->toBeNull()
        ->and(Activity::query()->where('action', 'machine_group.delete')->sole()->subject)->toBe('Europe');

    expect(mcpCall($workspace, machineToolsViewer($workspace), 'create_machine_group', ['name' => 'V'])['text'])->toContain('doesn’t allow that');
});

test('maintenance starts and ends like the maintenance dialog', function () {
    [$workspace, $machine, $key] = machineToolsSetup();

    $started = mcpCall($workspace, $key, 'start_maintenance', ['machine' => 'web-1', 'reason' => ' Kernel upgrade ']);
    expect($started['error'])->toBeFalse()
        ->and($started['data']['maintenance'])->toMatchArray(['reason' => 'Kernel upgrade', 'public' => true]);
    $machine->refresh();
    expect($machine->inMaintenance())->toBeTrue()
        ->and($machine->maintenance_until->between(now()->addMinutes(59), now()->addMinutes(61)))->toBeTrue()
        ->and(Activity::query()->where('action', 'machine.maintenance')->sole()->meta)->toMatchArray(['minutes' => 60, 'reason' => 'Kernel upgrade', 'via' => 'mcp']);

    mcpCall($workspace, $key, 'start_maintenance', ['machine' => 'web-1', 'minutes' => 1440, 'public' => false]);
    $machine->refresh();
    expect($machine->maintenance_until->gt(now()->addHours(23)))->toBeTrue()
        ->and($machine->maintenance_public)->toBeFalse()
        ->and($machine->maintenance_reason)->toBeNull();

    expect(mcpCall($workspace, $key, 'start_maintenance', ['machine' => 'web-1', 'minutes' => 45])['text'])->toContain('minutes must be one of')
        ->and(mcpCall($workspace, $key, 'start_maintenance', ['machine' => 'web-1', 'reason' => str_repeat('x', 201)])['error'])->toBeTrue();

    expect(mcpCall($workspace, $key, 'end_maintenance', ['machine' => 'web-1'])['data']['ended'])->toBeTrue()
        ->and($machine->fresh()->inMaintenance())->toBeFalse()
        ->and(Activity::query()->where('action', 'machine.maintenance_end')->count())->toBe(1);
    expect(mcpCall($workspace, $key, 'end_maintenance', ['machine' => 'web-1'])['data'])->toMatchArray(['ended' => false])
        ->and(Activity::query()->where('action', 'machine.maintenance_end')->count())->toBe(1);

    expect(mcpCall($workspace, machineToolsViewer($workspace), 'start_maintenance', ['machine' => 'web-1'])['text'])->toContain('doesn’t allow that');
});

test('maintenance planned and called off through MCP reads as done through MCP', function () {
    [$workspace, $machine, $key] = machineToolsSetup();

    $planned = mcpCall($workspace, $key, 'start_maintenance', ['machine' => 'web-1', 'starts_at' => now()->addDay()->toIso8601String(), 'reason' => 'Disk swap']);
    $id = $planned['data']['planned']['id'];
    expect(Activity::query()->where('action', 'machine.maintenance_scheduled')->sole()->meta)->toMatchArray(['via' => 'mcp'])
        ->and(Activity::query()->where('action', 'machine.maintenance_scheduled')->sole()->source)->toBe('mcp');

    expect(mcpCall($workspace, $key, 'end_maintenance', ['machine' => 'web-1', 'planned' => $id])['data']['calledOff'])->toBeTrue()
        ->and(Activity::query()->where('action', 'machine.maintenance_cancelled')->sole()->meta)->toMatchArray(['via' => 'mcp']);
    // Already called off: nothing more happened, so nothing more is recorded.
    expect(mcpCall($workspace, $key, 'end_maintenance', ['machine' => 'web-1', 'planned' => $id])['data']['calledOff'])->toBeFalse()
        ->and(Activity::query()->where('action', 'machine.maintenance_cancelled')->count())->toBe(1);
});

test('reboot_machine needs the name typed and holds alerts while it restarts', function () {
    machineToolsGateway();
    [$workspace, $machine, $key] = machineToolsSetup();

    expect(mcpCall($workspace, $key, 'reboot_machine', ['machine' => 'web-1'])['text'])->toContain('confirm')
        ->and(mcpCall($workspace, $key, 'reboot_machine', ['machine' => 'web-1', 'confirm' => 'web-2'])['text'])->toContain('doesn’t match')
        ->and(machineToolsSent())->toBe([])
        ->and(DaemonJob::query()->count())->toBe(0);

    $result = mcpCall($workspace, $key, 'reboot_machine', ['machine' => 'web-1', 'confirm' => 'web-1']);
    expect($result['error'])->toBeFalse()
        ->and($result['data']['job'])->toMatchArray(['kind' => 'reboot', 'state' => 'queued', 'machineId' => $machine->id])
        ->and(machineToolsSent()[0]['command']['job'])->toBe(['type' => 'reboot'])
        ->and(machineToolsSent()[0]['command']['actor']['name'])->toBe($workspace->owner->email);
    $machine->refresh();
    expect($machine->inMaintenance())->toBeTrue()
        ->and($machine->maintenance_reason)->toBe('Restarting after updates')
        ->and($machine->maintenance_public)->toBeFalse()
        ->and(Activity::query()->where('action', 'machine.reboot')->sole()->meta)->toMatchArray(['via' => 'mcp']);

    // A window already set is kept.
    $machine->forceFill(['maintenance_until' => now()->addHours(2), 'maintenance_reason' => 'Planned'])->save();
    mcpCall($workspace, $key, 'reboot_machine', ['machine' => 'web-1', 'confirm' => 'web-1']);
    expect($machine->fresh()->maintenance_reason)->toBe('Planned');

    // Offline machines can't take it.
    $machine->forceFill(['last_seen_at' => now()->subHour(), 'maintenance_until' => null])->save();
    expect(mcpCall($workspace, $key, 'reboot_machine', ['machine' => 'web-1', 'confirm' => 'web-1'])['text'])->toContain('offline')
        ->and(Activity::query()->where('action', 'machine.reboot')->count())->toBe(2)
        ->and($machine->fresh()->inMaintenance())->toBeFalse();

    expect(mcpCall($workspace, machineToolsViewer($workspace), 'reboot_machine', ['machine' => 'web-1', 'confirm' => 'web-1'])['text'])->toContain('doesn’t allow that');
});

test('checking for updates and reading the result', function () {
    machineToolsGateway();
    [$workspace, $machine, $key] = machineToolsSetup();

    expect(mcpCall($workspace, $key, 'get_updates_result', ['machine' => 'web-1'])['text'])->toContain('hasn’t been checked');

    $check = mcpCall($workspace, $key, 'check_updates', ['machine' => 'web-1']);
    expect($check['error'])->toBeFalse()
        ->and($check['data'])->toMatchArray(['kind' => 'package_updates', 'check' => true, 'state' => 'queued', 'finished' => false, 'report' => null])
        ->and(machineToolsSent()[0]['command']['job'])->toBe(['type' => 'package_updates', 'apply' => false, 'security_only' => false]);
    $jobId = $check['data']['jobId'];

    DaemonJob::query()->whereKey($jobId)->update(['state' => 'succeeded', 'log' => json_encode(['Reading package lists...', 'Done']), 'result' => json_encode([
        'manager' => 'apt',
        'upgradable' => ['openssl/noble-updates,noble-security 3.0.13-0ubuntu3.5 amd64 [upgradable from: 3.0.13-0ubuntu3.4]', 'curl/noble-updates 8.5.0-2ubuntu10.6 amd64 [upgradable from: 8.5.0-2ubuntu10.5]'],
        'security' => ['openssl/noble-updates,noble-security 3.0.13-0ubuntu3.5 amd64 [upgradable from: 3.0.13-0ubuntu3.4]'],
        'reboot_required' => true,
        'applied' => false,
    ])]);

    foreach ([['job' => $jobId], []] as $which) {
        $result = mcpCall($workspace, $key, 'get_updates_result', ['machine' => 'web-1', ...$which]);
        expect($result['error'])->toBeFalse()
            ->and($result['data'])->toMatchArray(['jobId' => $jobId, 'state' => 'succeeded', 'finished' => true, 'log' => ['Reading package lists...', 'Done'], 'canPick' => true])
            ->and($result['data']['report'])->toMatchArray(['manager' => 'apt', 'total' => 2, 'securityCount' => 1, 'rebootRequired' => true, 'applied' => false])
            ->and($result['data']['report']['packages'][0])->toBe(['name' => 'openssl', 'from' => '3.0.13-0ubuntu3.4', 'to' => '3.0.13-0ubuntu3.5', 'security' => true]);
    }

    // Jobs of other machines, other kinds, and nonsense ids aren't found.
    $other = machineToolsMachine($workspace, 'db-1');
    $deploy = DaemonJob::query()->forceCreate(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'deploy', 'state' => 'queued']);
    expect(mcpCall($workspace, $key, 'get_updates_result', ['machine' => 'db-1', 'job' => $jobId])['text'])->toContain('No updates job')
        ->and(mcpCall($workspace, $key, 'get_updates_result', ['machine' => 'web-1', 'job' => $deploy->id])['error'])->toBeTrue()
        ->and(mcpCall($workspace, $key, 'get_updates_result', ['machine' => 'web-1', 'job' => 'nope'])['text'])->toContain('Invalid arguments');

    // Viewers can follow a job, but not start a check.
    $viewer = machineToolsViewer($workspace);
    expect(mcpCall($workspace, $viewer, 'get_updates_result', ['machine' => 'web-1'])['error'])->toBeFalse()
        ->and(mcpCall($workspace, $viewer, 'check_updates', ['machine' => 'web-1'])['text'])->toContain('doesn’t allow that');

    // Commands off: said plainly.
    config()->set('daemon.commands_enabled', false);
    expect(mcpCall($workspace, $key, 'check_updates', ['machine' => $other->id])['text'])->toContain('isn’t turned on');
});

test('install_updates confirms, installs one batch at a time, and is recorded', function () {
    machineToolsGateway();
    [$workspace, $machine, $key] = machineToolsSetup();

    expect(mcpCall($workspace, $key, 'install_updates', ['machine' => 'web-1'])['text'])->toContain('confirm')
        ->and(DaemonJob::query()->count())->toBe(0);

    $result = mcpCall($workspace, $key, 'install_updates', ['machine' => 'web-1', 'confirm' => 'web-1', 'security_only' => true]);
    expect($result['error'])->toBeFalse()
        ->and($result['data'])->toMatchArray(['kind' => 'package_updates', 'check' => false, 'state' => 'queued'])
        ->and(machineToolsSent()[0]['command']['job'])->toBe(['type' => 'package_updates', 'apply' => true, 'security_only' => true])
        ->and(machineToolsSent()[0]['command']['timeout_secs'])->toBe(1800)
        ->and(Activity::query()->where('action', 'machine.update')->sole()->meta)->toMatchArray(['security_only' => true, 'packages' => null, 'via' => 'mcp']);

    // One install at a time.
    expect(mcpCall($workspace, $key, 'install_updates', ['machine' => 'web-1', 'confirm' => 'web-1'])['text'])->toContain('already being installed');
    DaemonJob::query()->update(['state' => 'succeeded']);

    // Picking packages: checked names, and a daemon that can.
    expect(mcpCall($workspace, $key, 'install_updates', ['machine' => 'web-1', 'confirm' => 'web-1', 'packages' => ['--reinstall']])['text'])->toContain('can’t start with a dash');
    $picked = mcpCall($workspace, $key, 'install_updates', ['machine' => 'web-1', 'confirm' => 'web-1', 'security_only' => true, 'packages' => ['openssl', 'libc6:amd64', 'openssl']]);
    expect($picked['data']['kind'])->toBe('package_install')
        ->and(machineToolsSent()[1]['command']['job'])->toBe(['type' => 'package_install', 'packages' => ['openssl', 'libc6:amd64']])
        ->and(Activity::query()->where('action', 'machine.update')->latest('id')->first()->meta)->toMatchArray(['packages' => 2, 'security_only' => false]);
    DaemonJob::query()->update(['state' => 'succeeded']);

    $machine->forceFill(['daemon_version' => 'v0.1.0-pre.1'])->save();
    expect(mcpCall($workspace, $key, 'install_updates', ['machine' => 'web-1', 'confirm' => 'web-1', 'packages' => ['openssl']])['text'])->toContain('can only install every update');

    expect(mcpCall($workspace, machineToolsViewer($workspace), 'install_updates', ['machine' => 'web-1', 'confirm' => 'web-1'])['text'])->toContain('doesn’t allow that');
});

test('install_packages confirms, sends a package_add job of its own, and needs a daemon that can', function () {
    machineToolsGateway();
    [$workspace, $machine, $key] = machineToolsSetup();

    expect(mcpCall($workspace, $key, 'install_packages', ['machine' => 'web-1', 'packages' => ['git']])['text'])->toContain('confirm')
        ->and(DaemonJob::query()->count())->toBe(0);

    // Nothing that reads as an option, or as apt's "remove this".
    expect(mcpCall($workspace, $key, 'install_packages', ['machine' => 'web-1', 'confirm' => 'web-1', 'packages' => ['-y']])['text'])->toContain('can’t start with a dash')
        ->and(mcpCall($workspace, $key, 'install_packages', ['machine' => 'web-1', 'confirm' => 'web-1', 'packages' => ['nginx-']])['text'])->toContain('can’t end with a dash')
        ->and(mcpCall($workspace, $key, 'install_packages', ['machine' => 'web-1', 'confirm' => 'web-1', 'packages' => array_map(fn ($i) => "pkg{$i}", range(1, 21))])['error'])->toBeTrue();

    $result = mcpCall($workspace, $key, 'install_packages', ['machine' => 'web-1', 'confirm' => 'web-1', 'packages' => ['git', 'unzip', 'git']]);
    expect($result['error'])->toBeFalse()
        ->and($result['data'])->toMatchArray(['packages' => ['git', 'unzip'], 'state' => 'queued'])
        ->and(machineToolsSent()[0]['command']['job'])->toBe(['type' => 'package_add', 'packages' => ['git', 'unzip']])
        ->and(Activity::query()->where('action', 'machine.packages_add')->sole()->meta)->toMatchArray(['packages' => ['git', 'unzip'], 'via' => 'mcp']);

    // One install at a time, with updates too.
    expect(mcpCall($workspace, $key, 'install_packages', ['machine' => 'web-1', 'confirm' => 'web-1', 'packages' => ['curl']])['text'])->toContain('already being installed')
        ->and(mcpCall($workspace, $key, 'install_updates', ['machine' => 'web-1', 'confirm' => 'web-1'])['text'])->toContain('already being installed');
    DaemonJob::query()->update(['state' => 'succeeded']);

    // An older daemon would refuse the job: said before it's sent.
    $machine->forceFill(['daemon_version' => 'v0.1.1-pre.34'])->save();
    expect(mcpCall($workspace, $key, 'install_packages', ['machine' => 'web-1', 'confirm' => 'web-1', 'packages' => ['git']])['text'])->toContain('too old');

    expect(mcpCall($workspace, machineToolsViewer($workspace), 'install_packages', ['machine' => 'web-1', 'confirm' => 'web-1', 'packages' => ['git']])['text'])->toContain('doesn’t allow that');
});

test('a finished package_add leaves waiting updates alone and checks again', function () {
    [$workspace, $machine] = machineToolsSetup();
    MachinePackageUpdate::query()->insert(['machine_id' => $machine->id, 'name' => 'openssl', 'installed' => '3.0.1', 'candidate' => '3.0.2', 'security' => true, 'created_at' => now(), 'updated_at' => now()]);
    Queue::fake();
    $job = DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'package_add', 'state' => 'succeeded', 'log' => [],
        'command' => ['job' => ['type' => 'package_add', 'packages' => ['git']]], 'result' => ['manager' => 'apt', 'applied' => true, 'held' => [], 'installed' => [['name' => 'git', 'version' => '1:2.43.0']]]]);

    PackageUpdates::ingest($job);

    expect(MachinePackageUpdate::query()->where('machine_id', $machine->id)->pluck('name')->all())->toBe(['openssl']);
    Queue::assertPushed(CheckMachineUpdates::class);
});

test('rescan_machine asks for a scan once, for anyone, and says when it can’t', function () {
    machineToolsGateway();
    [$workspace, $machine] = machineToolsSetup();
    $viewer = machineToolsViewer($workspace);

    $first = mcpCall($workspace, $viewer, 'rescan_machine', ['machine' => 'web-1']);
    expect($first['error'])->toBeFalse()
        ->and($first['data'])->toMatchArray(['started' => true, 'reusedRunningScan' => false])
        ->and($first['data']['job']['kind'])->toBe('discover')
        ->and($first['data']['job'])->not->toHaveKey('result')
        ->and($first['data']['services']['total'])->toBe(3)
        ->and(machineToolsSent()[0]['command']['job'])->toBe(['type' => 'discover']);

    $again = mcpCall($workspace, mcpKey($workspace, scopes: [Scopes::READ]), 'rescan_machine', ['machine' => 'web-1']);
    expect($again['data'])->toMatchArray(['started' => false, 'reusedRunningScan' => true])
        ->and($again['data']['job']['id'])->toBe($first['data']['job']['id'])
        ->and(DaemonJob::query()->where('kind', 'discover')->count())->toBe(1);

    $machine->forceFill(['last_seen_at' => now()->subHour()])->save();
    $offline = mcpCall($workspace, $viewer, 'rescan_machine', ['machine' => 'web-1']);
    expect($offline['error'])->toBeFalse()
        ->and($offline['data'])->toMatchArray(['started' => false, 'job' => null])
        ->and($offline['data']['note'])->toContain('offline')
        ->and($offline['data']['lastScannedAt'])->not->toBeNull();
});

test('add_machine gives a one-time install command and its status can be followed', function () {
    [$workspace, , $key] = machineToolsSetup();

    expect(mcpCall($workspace, $key, 'add_machine')['text'])->toContain('not published yet');

    config()->set('daemon.install_ready', true);
    config()->set('daemon.install_url', 'https://serveros.test/install.sh');
    config()->set('daemon.panel_url', 'https://serveros.test');
    $result = mcpCall($workspace, $key, 'add_machine');
    expect($result['error'])->toBeFalse()
        ->and($result['data']['command'])->toStartWith("curl -fsSL 'https://serveros.test/install.sh' | sudo bash -s -- --token='")
        ->and($result['data']['command'])->toContain("--panel='https://serveros.test'");
    preg_match('/--token=\x27([a-f0-9]{64})\x27/', $result['data']['command'], $match);
    $enrolment = DaemonEnrolment::query()->sole();
    expect($enrolment->id)->toBe($result['data']['enrolmentId'])
        ->and($enrolment->workspace_id)->toBe($workspace->id)
        ->and($enrolment->token_hash)->toBe(hash('sha256', $match[1]))
        ->and($enrolment->expires_at->between(now()->addMinutes(14), now()->addMinutes(16)))->toBeTrue();

    $status = fn () => mcpCall($workspace, $key, 'get_machine_connection_status', ['enrolment' => $enrolment->id])['data'];
    expect($status())->toMatchArray(['state' => 'waiting', 'machine' => null]);

    $machine = Machine::factory()->for($workspace)->create(['name' => 'box-1']);
    $enrolment->forceFill(['machine_id' => $machine->id, 'used_at' => now()])->save();
    expect($status())->toMatchArray(['state' => 'enrolled'])
        ->and($status()['machine'])->toMatchArray(['id' => $machine->id, 'name' => 'box-1']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'inventory' => ['services' => [], 'listeners' => [['port' => 22]]]])->save();
    expect($status())->toMatchArray(['state' => 'discovered', 'listeners' => 1]);

    $enrolment->forceFill(['machine_id' => null, 'used_at' => null, 'expires_at' => now()->subMinute()])->save();
    expect($status()['state'])->toBe('expired');

    // Not someone else's, not for viewers.
    $foreign = DaemonEnrolment::query()->create(['workspace_id' => Workspace::factory()->create()->id, 'token_hash' => str_repeat('a', 64), 'expires_at' => now()->addMinutes(15)]);
    expect(mcpCall($workspace, $key, 'get_machine_connection_status', ['enrolment' => $foreign->id])['text'])->toContain('No machine connection')
        ->and(mcpCall($workspace, machineToolsViewer($workspace), 'add_machine')['text'])->toContain('doesn’t allow that')
        ->and(mcpCall($workspace, machineToolsViewer($workspace), 'get_machine_connection_status', ['enrolment' => $enrolment->id])['text'])->toContain('doesn’t allow that');
});

test('add_machine keeps to the plan’s machine limit', function () {
    config()->set('daemon.install_ready', true);
    config()->set('daemon.install_url', 'https://serveros.test/install.sh');
    config()->set('daemon.panel_url', 'https://serveros.test');
    $workspace = Workspace::factory()->trialExpired()->create();
    $key = mcpKey($workspace);

    // With no plan, AI apps get no tool calls at all.
    expect(mcpCall($workspace, $key, 'add_machine')['text'])->toContain('has no plan, so AI apps can')
        ->and(DaemonEnrolment::query()->count())->toBe(0);

    $workspace->forceFill(['plan' => Plan::Pro, 'machine_limit_override' => 1])->save();
    expect(mcpCall($workspace, $key, 'add_machine')['error'])->toBeFalse();

    // One waiting to connect, someone else's, counts.
    $teammate = User::factory()->create();
    $workspace->members()->attach($teammate, ['role' => 'admin']);
    DaemonEnrolment::query()->update(['user_id' => $teammate->id]);
    expect(mcpCall($workspace, $key, 'add_machine')['text'])->toContain('reached its machine limit');
});

test('remove_machine needs the name typed, and only reaches this workspace', function () {
    [$workspace, $machine, $key] = machineToolsSetup();
    $theirs = machineToolsMachine(Workspace::factory()->create(), 'web-1');

    expect(mcpCall($workspace, $key, 'remove_machine', ['machine' => 'web-1'])['text'])->toContain('confirm')
        ->and(mcpCall($workspace, $key, 'remove_machine', ['machine' => 'web-1', 'confirm' => 'WEB-1'])['text'])->toContain('doesn’t match')
        ->and(mcpCall($workspace, $key, 'remove_machine', ['machine' => (string) $theirs->id, 'confirm' => 'web-1'])['text'])->toContain('No machine')
        ->and(mcpCall($workspace, machineToolsViewer($workspace), 'remove_machine', ['machine' => 'web-1', 'confirm' => 'web-1'])['text'])->toContain('doesn’t allow that')
        ->and(Machine::query()->count())->toBe(2);

    $result = mcpCall($workspace, $key, 'remove_machine', ['machine' => 'web-1', 'confirm' => 'web-1']);
    expect($result['error'])->toBeFalse()
        ->and($result['data']['removed'])->toBe(['id' => $machine->id, 'name' => 'web-1'])
        ->and(Machine::query()->find($machine->id))->toBeNull()
        ->and($theirs->fresh())->not->toBeNull();

    $activity = Activity::query()->where('action', 'machine.remove')->sole();
    expect($activity)->subject->toBe('web-1')->workspace_id->toBe($workspace->id)->user_id->toBe($workspace->owner_id)
        ->and($activity->meta)->toMatchArray(['via' => 'mcp']);
});

test('get_machine says when the plan has no status pages, and whether the fleet page shows it', function () {
    [$workspace, $machine, $key] = machineToolsSetup(Plan::Essential);
    StatusPage::draftFor($machine)->fill(['enabled' => true])->save();

    $page = mcpCall($workspace, $key, 'get_machine', ['machine' => 'web-1'])['data']['statusPage'];
    expect($page['planAllows'])->toBeFalse()
        ->and($page['own'])->toMatchArray(['enabled' => true, 'live' => false])
        ->and($page['fleet'])->toBeNull()
        ->and(mcpCall($workspace, $key, 'get_machine', ['machine' => 'web-1'])['data']['updates'])->toBeNull();

    $workspace->forceFill(['plan' => Plan::Business])->save();
    $fleet = new WorkspaceStatusPage(['slug' => 'acme-status', 'enabled' => true, 'public' => true, 'machines' => [$machine->id]]);
    $fleet->workspace_id = $workspace->id;
    $fleet->save();
    machineToolsMachine($workspace, 'db-1');

    expect(mcpCall($workspace, $key, 'get_machine', ['machine' => 'web-1'])['data']['statusPage']['fleet'])->toMatchArray(['slug' => 'acme-status', 'live' => true, 'showsThisMachine' => true])
        ->and(mcpCall($workspace, $key, 'get_machine', ['machine' => 'db-1'])['data']['statusPage']['fleet']['showsThisMachine'])->toBeFalse();
});

test('server work keeps to the pace the dashboard allows', function () {
    machineToolsGateway();
    [$workspace, $machine, $key] = machineToolsSetup();

    foreach (range(1, 3) as $i) {
        expect(mcpCall($workspace, $key, 'reboot_machine', ['machine' => 'web-1', 'confirm' => 'web-1'])['error'])->toBeFalse();
    }
    $fourth = mcpCall($workspace, $key, 'reboot_machine', ['machine' => 'web-1', 'confirm' => 'web-1']);
    expect($fourth['error'])->toBeTrue()
        ->and($fourth['text'])->toContain('3 times in the last minute')
        ->and(DaemonJob::query()->where('kind', 'reboot')->count())->toBe(3)
        ->and(Activity::query()->where('action', 'machine.reboot')->count())->toBe(3);

    // Per person: someone else in the workspace still can.
    $admin = User::factory()->create();
    $workspace->members()->attach($admin, ['role' => 'admin']);
    expect(mcpCall($workspace, mcpKey($workspace, $admin), 'reboot_machine', ['machine' => 'web-1', 'confirm' => 'web-1'])['error'])->toBeFalse();

    // A mistyped confirm doesn't use one up.
    foreach (range(1, 5) as $i) {
        mcpCall($workspace, $key, 'install_updates', ['machine' => 'web-1', 'confirm' => 'nope']);
    }
    expect(mcpCall($workspace, $key, 'install_updates', ['machine' => 'web-1', 'confirm' => 'web-1'])['error'])->toBeFalse();

    // A minute on, with the install finished, the person can reboot again.
    $this->travel(61)->seconds();
    $machine->forceFill(['last_seen_at' => now()])->save();
    DaemonJob::query()->whereIn('kind', ['package_updates', 'package_install'])->update(['state' => 'succeeded']);
    expect(mcpCall($workspace, $key, 'reboot_machine', ['machine' => 'web-1', 'confirm' => 'web-1'])['error'])->toBeFalse();
});

test('rescan_machine says when the gateway can’t be reached, and reads only this workspace', function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['error' => 'down'], 503)]);
    [$workspace, , $key] = machineToolsSetup();

    $result = mcpCall($workspace, $key, 'rescan_machine', ['machine' => 'web-1']);
    expect($result['error'])->toBeFalse()
        ->and($result['data'])->toMatchArray(['started' => false, 'job' => null])
        ->and($result['data']['note'])->toContain('Couldn’t reach the machine')
        ->and(DaemonJob::query()->sole()->state)->toBe('refused');

    $theirs = machineToolsMachine(Workspace::factory()->create(), 'theirs');
    expect(mcpCall($workspace, $key, 'rescan_machine', ['machine' => (string) $theirs->id])['text'])->toContain('No machine')
        ->and(DaemonJob::query()->where('machine_id', $theirs->id)->exists())->toBeFalse();
});

test('a machine in another workspace can’t be changed, put in maintenance or rebooted', function () {
    machineToolsGateway();
    [$workspace, , $key] = machineToolsSetup();
    $theirs = machineToolsMachine(Workspace::factory()->create(), 'theirs');
    $id = (string) $theirs->id;

    foreach ([
        ['update_machine', ['machine' => $id, 'name' => 'mine']],
        ['start_maintenance', ['machine' => $id]],
        ['end_maintenance', ['machine' => $id]],
        ['reboot_machine', ['machine' => $id, 'confirm' => 'theirs']],
        ['install_updates', ['machine' => $id, 'confirm' => 'theirs']],
        ['check_updates', ['machine' => $id]],
        ['get_updates_result', ['machine' => $id]],
        ['remove_machine_icon', ['machine' => $id]],
    ] as [$tool, $arguments]) {
        expect(mcpCall($workspace, $key, $tool, $arguments)['text'])->toContain('No machine');
    }

    $theirs->refresh();
    expect($theirs->name)->toBe('theirs')
        ->and($theirs->inMaintenance())->toBeFalse()
        ->and(DaemonJob::query()->count())->toBe(0)
        ->and(machineToolsSent())->toBe([])
        ->and(Activity::query()->count())->toBe(0);
});

test('remove_machine says what goes with a machine that receives backups', function () {
    [$workspace, $receiver, $key] = machineToolsSetup();
    $sender = machineToolsMachine($workspace, 'db-1');
    $destination = new BackupDestination(['name' => 'Vault', 'type' => 'machine', 'machine_id' => $receiver->id]);
    $destination->workspace_id = $workspace->id;
    $destination->save();
    $sender->forceFill(['backup_destination_id' => $destination->id, 'backup_schedule' => 'daily'])->save();

    $ask = mcpCall($workspace, $key, 'remove_machine', ['machine' => 'web-1']);
    expect($ask['error'])->toBeTrue()
        ->and($ask['text'])->toContain('backup destination “Vault”')
        ->and($ask['text'])->toContain('db-1 will stop backing up')
        ->and($destination->fresh())->not->toBeNull();

    $result = mcpCall($workspace, $key, 'remove_machine', ['machine' => 'web-1', 'confirm' => 'web-1']);
    expect($result['error'])->toBeFalse()
        ->and($result['data']['backupDestinationsRemoved'])->toBe([['id' => $destination->id, 'name' => 'Vault']])
        ->and($result['data']['machinesWithoutBackupDestination'])->toBe([['id' => $sender->id, 'name' => 'db-1']])
        ->and(BackupDestination::query()->find($destination->id))->toBeNull()
        ->and($sender->fresh()->backup_destination_id)->toBeNull();
});

test('a disk scan says what\'s using the disk and what\'s safe to clear', function () {
    machineToolsGateway();
    [$workspace, $machine, $key] = machineToolsSetup();

    $scan = mcpCall($workspace, $key, 'get_disk_usage', ['machine' => 'web-1']);
    expect($scan['error'])->toBeFalse()
        ->and($scan['data'])->toMatchArray(['state' => 'queued', 'finished' => false, 'suggestions' => []])
        ->and(machineToolsSent()[0]['command']['job'])->toBe(['type' => 'disk_usage']);

    $gb = 1024 ** 3;
    DaemonJob::query()->whereKey($scan['data']['jobId'])->update(['state' => 'succeeded', 'finished_at' => now(), 'result' => json_encode([
        'path' => '/', 'total' => 24 * $gb, 'used' => 20 * $gb, 'available' => 4 * $gb, 'partial' => false, 'seconds' => 3.2,
        'dirs' => [['path' => '/var/lib/postgresql', 'bytes' => 9 * $gb], ['path' => '/var/log', 'bytes' => (int) (2.8 * $gb)], ['path' => '/var/cache/apt', 'bytes' => 330 * 1024 ** 2]],
        'journal' => (int) (2.3 * $gb),
        'docker' => [['kind' => 'Images', 'count' => '3', 'active' => '0', 'size' => '1.371GB', 'reclaimable' => '1.371GB (99%)'], ['kind' => 'Containers', 'count' => '0', 'active' => '0', 'size' => '0B', 'reclaimable' => '0B']],
    ])]);

    $result = mcpCall($workspace, $key, 'get_disk_usage', ['machine' => 'web-1', 'job' => $scan['data']['jobId']]);
    expect($result['data'])->toMatchArray(['state' => 'succeeded', 'finished' => true, 'available' => 4 * $gb, 'journal' => (int) (2.3 * $gb)])
        ->and(array_column($result['data']['suggestions'], 'command'))->toBe(['journalctl --vacuum-size=200M', 'docker system prune -a', 'apt-get clean', null])
        ->and($result['data']['suggestions'][1]['detail'])->toStartWith('Images: 1.371GB.')
        ->and($result['data']['suggestions'][3]['title'])->toStartWith('Postgres data takes')
        // Only one machine's scan is its own.
        ->and(mcpCall($workspace, $key, 'get_disk_usage', ['machine' => machineToolsMachine($workspace, 'db-1')->id, 'job' => $scan['data']['jobId']])['text'])->toContain('No disk scan');

    // A scan of one folder, and paths that aren't allowed.
    mcpCall($workspace, $key, 'get_disk_usage', ['machine' => 'web-1', 'path' => '/var/lib']);
    expect(machineToolsSent()[1]['command']['job'])->toBe(['type' => 'disk_usage', 'path' => '/var/lib'])
        ->and(mcpCall($workspace, $key, 'get_disk_usage', ['machine' => 'web-1', 'path' => '/var/../etc'])['text'])->toContain('Invalid arguments')
        ->and(mcpCall($workspace, $key, 'get_disk_usage', ['machine' => 'web-1', 'path' => 'var'])['text'])->toContain('Invalid arguments');

    // An older daemon can't scan.
    $machine->forceFill(['daemon_version' => 'v0.1.1-pre.18'])->save();
    expect(mcpCall($workspace, $key, 'get_disk_usage', ['machine' => 'web-1'])['text'])->toContain('too old');
});
