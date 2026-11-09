<?php

use App\Actions\Workspaces\CreateWorkspace;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;

test('only someone’s first workspace gets the Pro trial', function () {
    $user = User::factory()->create();

    $first = app(CreateWorkspace::class)->create($user, name: 'First');
    expect($first->onTrial())->toBeTrue()
        ->and($first->effectivePlan())->toBe(Workspace::TRIAL_PLAN)
        ->and($user->fresh()->trial_used_at)->not->toBeNull();

    $this->actingAs($user)->post('/workspaces', ['name' => 'Second'])->assertRedirect();
    $second = Workspace::query()->where('name', 'Second')->sole();
    expect($second->trial_ends_at)->toBeNull()
        ->and($second->onTrial())->toBeFalse()
        ->and($second->effectivePlan())->toBeNull();
});

test('deleting the trial workspace doesn’t give another trial', function () {
    $user = User::factory()->create();
    app(CreateWorkspace::class)->create($user, name: 'First')->delete();

    // With no workspace left, opening the dashboard makes one: no trial this time.
    $again = app(CreateWorkspace::class)->create($user->fresh());
    expect($again->onTrial())->toBeFalse();
});

test('the dialog says whether a new workspace gets the trial', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->getJson('/workspaces/movable-machines')
        ->assertJsonPath('trial', true)
        ->assertJsonPath('limit', Workspace::TRIAL_PLAN->machineLimit());

    app(CreateWorkspace::class)->create($user, name: 'First');
    $this->actingAs($user->fresh())->getJson('/workspaces/movable-machines')
        ->assertJsonPath('trial', false)
        ->assertJsonPath('limit', null);
});

test('machines moved into a later workspace land without a plan, so there’s no fresh trial to gain', function () {
    $user = User::factory()->create();
    $from = app(CreateWorkspace::class)->create($user, name: 'First');
    $from->forceFill(['trial_ends_at' => now()->subDay()])->save();
    // Connected while it still had room; quietly, as the limit now says no.
    $machines = Machine::factory()->for($from)->count(Workspace::TRIAL_PLAN->machineLimit() + 1)->createQuietly();

    // More than the trial's allowance is fine: this workspace has no trial to stretch.
    $this->actingAs($user->fresh())->post('/workspaces', ['name' => 'Fresh start', 'machines' => $machines->modelKeys()])->assertRedirect();

    $to = Workspace::query()->where('name', 'Fresh start')->sole();
    expect($to->machines()->count())->toBe($machines->count())
        ->and($to->effectivePlan())->toBeNull()
        ->and($to->canAddMachine())->toBeFalse();
});

test('existing owners count as having had their trial', function () {
    $owner = Workspace::factory()->create()->owner;
    $owner->forceFill(['trial_used_at' => null])->save();

    // What the migration's backfill does.
    DB::table('users')->whereNull('trial_used_at')->update([
        'trial_used_at' => DB::raw('(select min(workspaces.created_at) from workspaces where workspaces.owner_id = users.id)'),
    ]);

    expect($owner->fresh()->trial_used_at)->not->toBeNull()
        ->and(User::factory()->create()->fresh()->trial_used_at)->toBeNull();
});

test('workspaces can’t be created in a flood', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    foreach (range(1, 10) as $i) {
        $this->post('/workspaces', ['name' => "Workspace {$i}"])->assertRedirect();
    }
    $this->post('/workspaces', ['name' => 'One too many'])->assertTooManyRequests();
});
