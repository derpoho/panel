<?php

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

function machineDeleteUrl(Workspace $workspace, Machine $machine): string
{
    return 'https://'.$workspace->host().'/machines/'.$machine->id;
}

test('a machine manager can remove a machine and its jobs', function () {
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    DaemonJob::query()->forceCreate([
        'id' => (string) Str::uuid(),
        'machine_id' => $machine->id,
        'kind' => 'deploy',
        'state' => 'queued',
    ]);

    $this->actingAs($workspace->owner)
        ->delete(machineDeleteUrl($workspace, $machine))
        ->assertRedirect(route('workspace.machines.index', $workspace->slug));

    expect(Machine::query()->find($machine->id))->toBeNull()
        ->and(DaemonJob::query()->where('machine_id', $machine->id)->exists())->toBeFalse();
});

test('viewers cannot remove machines', function () {
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    $this->actingAs($viewer)
        ->delete(machineDeleteUrl($workspace, $machine))
        ->assertForbidden();

    expect(Machine::query()->find($machine->id))->not->toBeNull();
});

test('a machine from another workspace cannot be removed', function () {
    $workspace = Workspace::factory()->create();
    $other = Machine::factory()->create();

    $this->actingAs($workspace->owner)
        ->delete(machineDeleteUrl($workspace, $other))
        ->assertNotFound();

    expect(Machine::query()->find($other->id))->not->toBeNull();
});
