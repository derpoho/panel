<?php

use App\Enums\Plan;
use App\Mcp\Tools\MachineTools;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\PackageRollout;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Machines\PackageUpdates;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(fn () => config()->set('mcp.groups', [MachineTools::class]));

/** @return array{0: Workspace, 1: Machine, 2: Machine, 3: string} */
function packageToolsSetup(): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create(['name' => 'Acme']);
    $machines = [];
    foreach (['web-1' => '24.04', 'web-2' => '22.04'] as $name => $release) {
        $machine = Machine::factory()->for($workspace)->create(['name' => $name]);
        $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'daemon_version' => 'v0.1.1-pre.26',
            'os_release' => ['id' => 'ubuntu', 'version_id' => $release, 'pretty_name' => "Ubuntu {$release} LTS"]])->save();
        $job = DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'package_updates', 'state' => 'succeeded', 'log' => [],
            'command' => ['job' => ['type' => 'package_updates', 'apply' => false]], 'finished_at' => now(),
            'result' => ['manager' => 'apt', 'applied' => false, 'reboot_required' => $name === 'web-2', 'held' => $name === 'web-1' ? ['nginx'] : [], 'packages' => [
                ['name' => 'openssl', 'installed' => $release === '24.04' ? '3.0.13-0ubuntu3.4' : '3.0.2-0ubuntu1.17', 'candidate' => $release === '24.04' ? '3.0.13-0ubuntu3.5' : '3.0.2-0ubuntu1.18', 'security' => true, 'arch' => 'amd64'],
                ...($name === 'web-1' ? [['name' => 'curl', 'installed' => '8.5.0-2ubuntu10.5', 'candidate' => '8.5.0-2ubuntu10.6', 'security' => false, 'arch' => 'amd64']] : []),
            ]]]);
        PackageUpdates::ingest($job);
        $machines[] = $machine;
    }

    return [$workspace, $machines[0], $machines[1], mcpKey($workspace)];
}

test('list_package_updates lists updates by package, with versions by OS release', function () {
    [$workspace, $one, $two, $key] = packageToolsSetup();

    $data = mcpCall($workspace, $key, 'list_package_updates')['data'];
    expect($data['summary'])->toMatchArray(['updates' => 3, 'packages' => 2, 'security' => 2, 'machinesWithUpdates' => 2, 'rebootRequired' => 1, 'neverChecked' => 0])
        ->and(collect($data['packages'])->pluck('name')->all())->toBe(['openssl', 'curl', 'nginx'])
        ->and($data['packages'][0]['versions'])->toHaveCount(2)
        ->and(collect($data['packages'][0]['versions'])->pluck('osRelease')->sort()->values()->all())->toBe(['ubuntu 22.04', 'ubuntu 24.04'])
        ->and($data['packages'][2]['heldOn'])->toBe([['id' => $one->id, 'name' => 'web-1']]);

    expect(collect(mcpCall($workspace, $key, 'list_package_updates', ['security' => true])['data']['packages'])->pluck('name')->all())->toBe(['openssl'])
        ->and(collect(mcpCall($workspace, $key, 'list_package_updates', ['machine' => 'web-2'])['data']['packages'])->pluck('name')->all())->toBe(['openssl']);

    // list_machines and get_machine carry each machine's summary.
    $row = collect(mcpCall($workspace, $key, 'list_machines')['data']['machines'])->firstWhere('name', 'web-1');
    expect($row['osUpdates'])->toMatchArray(['waiting' => 2, 'security' => 1, 'rebootRequired' => false, 'held' => ['nginx'], 'complete' => true]);
    expect(mcpCall($workspace, $key, 'get_machine', ['machine' => 'web-2'])['data']['osUpdates'])->toMatchArray(['waiting' => 1, 'rebootRequired' => true]);
});

test('create_package_rollout shows its plan, needs the machine count to go ahead, and checks exact versions', function () {
    [$workspace, $one, $two, $key] = packageToolsSetup();

    $dry = mcpCall($workspace, $key, 'create_package_rollout', ['packages' => [['name' => 'openssl']], 'confirm' => '']);
    expect($dry['error'])->toBeTrue()
        ->and($dry['text'])->toContain('on 2 machines: web-1, web-2')->toContain('“2 machines”')
        ->and(PackageRollout::query()->count())->toBe(0);

    $exact = mcpCall($workspace, $key, 'create_package_rollout', ['packages' => [['name' => 'openssl', 'version' => '3.0.13-0ubuntu3.5']], 'mode' => 'exact', 'confirm' => '2 machines']);
    expect($exact['error'])->toBeTrue()->and($exact['text'])->toContain('isn’t offered on web-2');

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    expect(mcpCall($workspace, mcpKey($workspace, $viewer), 'create_package_rollout', ['packages' => [['name' => 'openssl']], 'confirm' => '2 machines'])['error'])->toBeTrue();

    $made = mcpCall($workspace, $key, 'create_package_rollout', ['packages' => [['name' => 'openssl', 'version' => '3.0.13-0ubuntu3.5']], 'mode' => 'exact', 'machines' => [$one->id], 'confirm' => '1 machine', 'reboot' => 'if_required']);
    expect($made['error'])->toBeFalse()
        ->and($made['data']['rollout'])->toMatchArray(['state' => 'running', 'mode' => 'exact', 'reboot' => 'if_required', 'total' => 1, 'running' => 1])
        ->and(DaemonJob::query()->where('kind', 'package_install_versions')->where('machine_id', $one->id)->exists())->toBeTrue()
        ->and(Activity::query()->where('action', 'updates.rollout')->value('meta'))->toMatchArray(['via' => 'mcp', 'machines' => 1, 'mode' => 'exact']);

    $id = $made['data']['rollout']['id'];
    $got = mcpCall($workspace, $key, 'get_package_rollout', ['rollout' => $id])['data']['rollout'];
    expect($got['machines'][0])->toMatchArray(['name' => 'web-1', 'state' => 'running'])
        ->and(mcpCall($workspace, $key, 'get_package_rollout')['data']['rollouts'][0]['id'])->toBe($id)
        ->and(mcpCall($workspace, $key, 'get_package_rollout', ['rollout' => 999999])['text'])->toContain('No rollout #999999');
});
