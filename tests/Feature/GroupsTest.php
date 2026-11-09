<?php

use App\Models\Machine;
use App\Models\MachineGroup;
use App\Models\ServiceGroup;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function groupsHost(Workspace $workspace): string
{
    return 'https://'.$workspace->host();
}

test('services can be grouped, and a service sits in one group at most', function () {
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $base = groupsHost($workspace).'/machines/'.$machine->id.'/service-groups';
    $owner = $workspace->owner;

    $this->actingAs($owner)->post($base, ['name' => ' Web ', 'services' => ['docker:aaa', 'docker:bbb']])->assertRedirect();
    $this->actingAs($owner)->post($base, ['name' => 'Data', 'services' => ['docker:bbb', 'docker:ccc']])->assertRedirect();

    $web = ServiceGroup::query()->where('name', 'Web')->sole();
    $data = ServiceGroup::query()->where('name', 'Data')->sole();
    // docker:bbb moved to Data.
    expect($web->services)->toBe(['docker:aaa'])
        ->and($data->services)->toBe(['docker:bbb', 'docker:ccc']);

    $this->actingAs($owner)->put($base.'/'.$web->id, ['name' => 'Frontend', 'services' => ['docker:aaa', 'docker:ddd']])->assertRedirect();
    expect($web->fresh()->name)->toBe('Frontend');

    $this->actingAs($owner)->get(groupsHost($workspace).'/machines/'.$machine->id)
        ->assertInertia(fn (Assert $page) => $page
            ->where('serviceGroups', [
                ['id' => $data->id, 'name' => 'Data', 'services' => ['docker:bbb', 'docker:ccc'], 'iconUrl' => null],
                ['id' => $web->id, 'name' => 'Frontend', 'services' => ['docker:aaa', 'docker:ddd'], 'iconUrl' => null],
            ]));

    $this->actingAs($owner)->delete($base.'/'.$data->id)->assertRedirect();
    expect(ServiceGroup::query()->count())->toBe(1);
});

test('machines can be grouped and moved between groups', function () {
    $workspace = Workspace::factory()->create();
    [$a, $b, $c] = Machine::factory()->count(3)->for($workspace)->create();
    $owner = $workspace->owner;
    $base = groupsHost($workspace).'/machine-groups';

    $this->actingAs($owner)->post($base, ['name' => 'EU', 'machines' => [$a->id, $b->id]])->assertRedirect();
    $eu = MachineGroup::query()->sole();
    expect($a->fresh()->machine_group_id)->toBe($eu->id)
        ->and($c->fresh()->machine_group_id)->toBeNull();

    // Editing sets exactly the chosen machines.
    $this->actingAs($owner)->put($base.'/'.$eu->id, ['name' => 'Europe', 'machines' => [$b->id, $c->id]])->assertRedirect();
    expect($a->fresh()->machine_group_id)->toBeNull()
        ->and($c->fresh()->machine_group_id)->toBe($eu->id);

    $this->actingAs($owner)->get(groupsHost($workspace).'/machines')
        ->assertInertia(fn (Assert $page) => $page
            ->where('machineGroups', [['id' => $eu->id, 'name' => 'Europe', 'policyId' => null]])
            ->where('machines', function ($machines) use ($a, $b, $c, $eu) {
                $groups = collect($machines)->pluck('groupId', 'id')->sortKeys()->all();

                return $groups === collect([$a->id => null, $b->id => $eu->id, $c->id => $eu->id])->sortKeys()->all();
            }));

    // Deleting the group keeps the machines.
    $this->actingAs($owner)->delete($base.'/'.$eu->id)->assertRedirect();
    expect($b->fresh())->not->toBeNull()
        ->and($b->fresh()->machine_group_id)->toBeNull();
});

test('groups stay inside their workspace and viewers cannot change them', function () {
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $other = Machine::factory()->create();
    $owner = $workspace->owner;

    $this->actingAs($owner)->post(groupsHost($workspace).'/machine-groups', ['name' => 'X', 'machines' => [$other->id]])
        ->assertSessionHasErrors('machines.0');

    $foreign = MachineGroup::query()->forceCreate(['workspace_id' => $other->workspace_id, 'name' => 'Theirs']);
    $this->actingAs($owner)->delete(groupsHost($workspace).'/machine-groups/'.$foreign->id)->assertNotFound();

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)->post(groupsHost($workspace).'/machines/'.$machine->id.'/service-groups', ['name' => 'X', 'services' => []])
        ->assertForbidden();
});

test('a group can have an icon, removed with it', function () {
    Storage::fake('public');
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $base = groupsHost($workspace).'/machines/'.$machine->id.'/service-groups';

    $this->actingAs($workspace->owner)->post($base, ['name' => 'Cache', 'services' => ['app:shop', 'docker:bbb']])->assertRedirect();
    $group = ServiceGroup::query()->sole();

    $this->actingAs($workspace->owner)->post("{$base}/{$group->id}/icon", ['icon' => UploadedFile::fake()->image('c.png', 32, 32)])->assertRedirect();
    $path = $group->fresh()->icon_path;
    Storage::disk('public')->assertExists($path);
    expect($group->fresh()->toPageProps()['iconUrl'])->not->toBeNull()
        ->and($group->fresh()->services)->toBe(['app:shop', 'docker:bbb']);

    $this->actingAs($workspace->owner)->delete("{$base}/{$group->id}")->assertRedirect();
    Storage::disk('public')->assertMissing($path);
});
