<?php

use App\Models\Machine;
use App\Models\Workspace;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Tabbed pages keep their open tab as the last part of the address
 * (/machines/5/usage), so each page's route takes it as an optional segment.
 */

function tabMachine(): array
{
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'inventory' => ['scanned_at' => now()->timestamp, 'complete' => true, 'listeners' => [], 'services' => [
            ['key' => 'systemd:nginx.service', 'name' => 'nginx', 'kind' => 'web_server', 'manager' => 'systemd',
                'status' => 'running', 'ports' => [80], 'confidence' => 90, 'details' => []],
        ]],
    ])->save();

    return [$workspace, $machine, 'https://'.$workspace->host().'/machines/'.$machine->id];
}

test('a machine, its settings and its services open on any of their tabs', function () {
    [$workspace, $machine, $url] = tabMachine();
    $this->actingAs($workspace->owner);

    foreach (['', '/usage', '/deployments'] as $tab) {
        $this->get($url.$tab)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('workspace/MachineShow')->where('machine.id', $machine->id)->etc());
    }

    foreach (['/settings', '/settings/status'] as $tab) {
        $this->get($url.$tab)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('workspace/MachineSettings')->etc());
    }

    foreach (['', '/logs', '/details'] as $tab) {
        $this->get($url.'/services/systemd:nginx.service'.$tab)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('workspace/ServiceShow')->where('service.key', 'systemd:nginx.service')->etc());
    }
});

test('a tab a page does not have is not found', function () {
    [$workspace, , $url] = tabMachine();
    $this->actingAs($workspace->owner);

    $this->get($url.'/nope')->assertNotFound();
    $this->get($url.'/settings/nope')->assertNotFound();
    $this->get($url.'/services/systemd:nginx.service/nope')->assertNotFound();
});

test('backups open on any of their tabs', function () {
    [$workspace] = tabMachine();
    $base = 'https://'.$workspace->host();

    foreach (['/backups', '/backups/destinations', '/backups/history'] as $path) {
        $this->actingAs($workspace->owner)->get($base.$path)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('workspace/Backups')->etc());
    }
});
