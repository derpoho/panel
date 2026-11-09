<?php

use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\SetupGuide;
use Illuminate\Support\Facades\DB;

function usedOn(Workspace $workspace, string $day): void
{
    DB::table('workspace_visits')->insert(['workspace_id' => $workspace->id, 'user_id' => $workspace->owner_id, 'day' => $day]);
}

test('opening a workspace page counts one day of use, not one per page', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();

    $this->actingAs($user)->get('https://'.$workspace->host().'/')->assertOk();
    $this->actingAs($user)->get('https://'.$workspace->host().'/machines')->assertOk();

    expect(DB::table('workspace_visits')->where('workspace_id', $workspace->id)->count())->toBe(1);
});

test('the setup guide only ticks off services once one is adopted or deployed', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    $machine = Machine::factory()->for($workspace)->create(['inventory' => ['services' => [['name' => 'nginx', 'details' => []]]]]);
    $step = fn () => collect(SetupGuide::for($workspace, $user)['groups'][0]['steps'])->firstWhere('key', 'service')['done'];

    expect($step())->toBeFalse();

    $machine->forceFill(['inventory' => ['services' => [['name' => 'nginx', 'details' => ['managed' => 'true']]]]])->save();
    expect($step())->toBeTrue();
});
