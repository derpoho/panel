<?php

use App\Models\Activity;
use App\Models\BackupDestination;
use App\Models\Machine;
use App\Models\Monitor;
use App\Models\Workspace;
use App\Models\WorkspaceStatusPage;

test('creating a workspace can move machines in, with their monitors and backups', function () {
    $from = Workspace::factory()->create();
    $owner = $from->owner;
    $web = Machine::factory()->for($from)->create(['name' => 'web']);
    $db = Machine::factory()->for($from)->create(['name' => 'db']);

    // One destination used by both: web moves, db stays, so web gets a copy.
    $destination = BackupDestination::query()->forceCreate(['workspace_id' => $from->id, 'name' => 'S3', 'type' => 's3', 'settings' => ['bucket' => 'b'], 'status' => 'ok']);
    $web->forceFill(['backup_destination_id' => $destination->id])->save();
    $db->forceFill(['backup_destination_id' => $destination->id])->save();
    $monitor = Monitor::query()->forceCreate(['workspace_id' => $from->id, 'machine_id' => $web->id, 'name' => 'site', 'type' => 'http', 'url' => 'https://example.com', 'interval_seconds' => 60, 'timeout_seconds' => 10]);
    $page = WorkspaceStatusPage::query()->forceCreate(['workspace_id' => $from->id, 'slug' => 'from-status', 'title' => 'Status', 'machines' => [$web->id, $db->id]]);

    $this->actingAs($owner)->getJson('/workspaces/movable-machines')
        ->assertOk()
        ->assertJsonPath('workspaces.0.machines.1.name', 'web');

    $this->actingAs($owner)->post('/workspaces', ['name' => 'Client B', 'machines' => [$web->id]])->assertRedirect();

    $to = Workspace::query()->where('name', 'Client B')->sole();
    expect($web->refresh()->workspace_id)->toBe($to->id)
        ->and($db->refresh()->workspace_id)->toBe($from->id)
        ->and($monitor->refresh()->workspace_id)->toBe($to->id)
        ->and($page->refresh()->machines)->toBe([$db->id])
        ->and($db->backup_destination_id)->toBe($destination->id)
        ->and(BackupDestination::query()->find($web->backup_destination_id)->workspace_id)->toBe($to->id)
        ->and(Activity::query()->where('workspace_id', $to->id)->where('action', 'machine.move_in')->exists())->toBeTrue();
});

test('machines from a workspace you cannot manage machines in are refused', function () {
    $theirs = Machine::factory()->for(Workspace::factory()->create())->create();
    $user = Workspace::factory()->create()->owner;

    $this->actingAs($user)->post('/workspaces', ['name' => 'Mine', 'machines' => [$theirs->id]])->assertSessionHasErrors('machines');
    expect(Workspace::query()->where('name', 'Mine')->exists())->toBeFalse();
});

test('a new workspace takes no more machines than its trial allows', function () {
    $from = Workspace::factory()->create(['machine_limit_override' => -1]);
    $machines = Machine::factory()->for($from)->count(Workspace::TRIAL_PLAN->machineLimit() + 1)->create();

    $this->actingAs($from->owner)->post('/workspaces', ['name' => 'Too many', 'machines' => $machines->modelKeys()])->assertSessionHasErrors('machines');
});
