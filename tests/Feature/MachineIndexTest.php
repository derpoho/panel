<?php

use App\Enums\MachineEnvironment;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia as Assert;

test('the machines page lists only the workspace machines', function () {
    $workspace = Workspace::factory()->create();
    Machine::factory()->for($workspace)->environment(MachineEnvironment::Staging)->create([
        'name' => 'staging-01',
        'ip_address' => '198.51.100.21',
        'provider' => 'Linode',
        'services_count' => 2,
        'memory_used_mb' => 1434,
        'cpu_percent' => 38,
        'last_backup_at' => now()->subMinutes(21),
    ]);
    Machine::factory()->create(['name' => 'someone-elses']);

    $this->actingAs($workspace->owner)
        ->get('https://'.$workspace->host().'/machines')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/Machines')
            ->has('machines', 1)
            ->where('machines.0', [
                'id' => $workspace->machines()->first()->id,
                'name' => 'staging-01',
                'ip' => '198.51.100.21',
                'environment' => 'staging',
                'provider' => 'Linode',
                'providerManual' => false,
                'region' => null,
                'regionManual' => false,
                'tier' => null,
                'labels' => [],
                'services' => 2,
                'memory' => '1.4 GB',
                'memoryMb' => 1434,
                'cpu' => 38,
                'groupId' => null,
                'iconUrl' => null,
                'lastBackup' => '21m ago',
                'uid' => null,
                'connection' => 'demo',
                'daemonVersion' => null,
                'lastSeen' => null,
                'inventory' => null,
                'facts' => null,
                'disks' => [],
                'diskPercent' => null,
                'maintenance' => null,
                'setup' => null,
                'setupFindings' => null,
            ]));
});

test('outsiders cannot list a workspace machines', function () {
    $workspace = Workspace::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get('https://'.$workspace->host().'/machines')
        ->assertForbidden();
});
