<?php

use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\Plan;
use App\Http\Controllers\WorkspaceWelcomeController;
use App\Models\Activity;
use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia as Assert;

function welcomeUrl(Workspace $workspace, string $path = ''): string
{
    return 'https://'.$workspace->host().'/'.$path;
}

test('only a first workspace someone makes for themselves starts with the welcome flow', function () {
    $user = User::factory()->create();

    $first = app(CreateWorkspace::class)->create($user, name: 'First');
    $second = app(CreateWorkspace::class)->create($user->fresh(), name: 'Second');

    expect($first->onboarded_at)->toBeNull()
        ->and($second->onboarded_at)->not->toBeNull();

    // Made by staff for someone else: straight to the dashboard.
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    $forThem = app(CreateWorkspace::class)->create(User::factory()->create(), name: 'Theirs');
    expect($forThem->onboarded_at)->not->toBeNull();
});

test('a new owner is sent to the welcome flow, but teammates and existing workspaces are not', function () {
    $workspace = Workspace::factory()->welcoming()->create();
    $teammate = User::factory()->create();
    $workspace->members()->attach($teammate, ['role' => 'admin']);

    $this->actingAs($workspace->owner)->get(welcomeUrl($workspace))
        ->assertRedirect(welcomeUrl($workspace, 'welcome'));
    $this->actingAs($workspace->owner)->get(welcomeUrl($workspace, 'welcome'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('welcome/Welcome')
            ->where('recommended', 'pro')
            ->has('plans', 3));

    $this->actingAs($teammate)->get(welcomeUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page->component('workspace/Home'));
    $this->actingAs($teammate)->get(welcomeUrl($workspace, 'welcome'))
        ->assertRedirect(rtrim(welcomeUrl($workspace), '/'));

    // Everyone from before the welcome flow goes straight to their dashboard.
    $existing = Workspace::factory()->create();
    $this->actingAs($existing->owner)->get(welcomeUrl($existing))
        ->assertInertia(fn (Assert $page) => $page->component('workspace/Home'));
});

test('the answers are kept, and the plan recommended from them', function () {
    $workspace = Workspace::factory()->welcoming()->create();

    $this->actingAs($workspace->owner)
        ->post(welcomeUrl($workspace, 'welcome/about'), ['segment' => 'hosting', 'servers' => '20+', 'team' => true])
        ->assertRedirect();

    expect($workspace->fresh()->welcome)->toMatchArray([
        'segment' => 'hosting', 'servers' => '20+', 'team' => true, 'recommended' => 'business',
    ]);

    $this->actingAs($workspace->owner)
        ->post(welcomeUrl($workspace, 'welcome/about'), ['segment' => 'pirate', 'servers' => '1', 'team' => false])
        ->assertSessionHasErrors('segment');
});

test('the trial runs on the plan chosen in the welcome flow', function () {
    $workspace = Workspace::factory()->welcoming()->create();

    $this->actingAs($workspace->owner)
        ->post(welcomeUrl($workspace, 'welcome/plan'), ['plan' => 'business', 'interval' => 'annual'])
        ->assertRedirect();

    $workspace->refresh();
    expect($workspace->trial_plan)->toBe(Plan::Business)
        ->and($workspace->effectivePlan())->toBe(Plan::Business)
        ->and($workspace->machineLimit())->toBeNull()
        ->and($workspace->welcome['interval'])->toBe('annual')
        ->and(Activity::query()->where('action', 'workspace.trial_plan')->sole()->subject)->toBe('Business');

    // When the trial ends, there's no plan until one is chosen.
    $workspace->forceFill(['trial_ends_at' => now()->subDay()])->save();
    expect($workspace->fresh()->effectivePlan())->toBeNull();
});

test('finishing or skipping lands on the dashboard for good', function () {
    $workspace = Workspace::factory()->welcoming()->create();

    $this->actingAs($workspace->owner)
        ->post(welcomeUrl($workspace, 'welcome/finish'), ['skipped_at' => 'plan'])
        ->assertRedirect(rtrim(welcomeUrl($workspace), '/'));

    expect($workspace->fresh()->onboarded_at)->not->toBeNull()
        ->and($workspace->fresh()->welcome['skipped_at'])->toBe('plan');

    $this->actingAs($workspace->owner)->get(welcomeUrl($workspace, 'welcome'))->assertRedirect(rtrim(welcomeUrl($workspace), '/'));
    $this->actingAs($workspace->owner)
        ->post(welcomeUrl($workspace, 'welcome/plan'), ['plan' => 'business', 'interval' => 'monthly'])
        ->assertForbidden();
});

test('the recommendation follows the servers and who will use it', function (array $answers, Plan $plan) {
    expect(WorkspaceWelcomeController::recommend($answers))->toBe($plan);
})->with([
    'no answers yet' => [[], Plan::Pro],
    'one server, alone' => [['segment' => 'own', 'servers' => '1', 'team' => false], Plan::Essential],
    'one server, a team' => [['segment' => 'own', 'servers' => '1', 'team' => true], Plan::Pro],
    'a handful' => [['segment' => 'own', 'servers' => '2-5', 'team' => false], Plan::Pro],
    'many servers' => [['segment' => 'exploring', 'servers' => '6-20', 'team' => false], Plan::Business],
    'an agency team' => [['segment' => 'agency', 'servers' => '2-5', 'team' => true], Plan::Business],
    'a hosting company on its own' => [['segment' => 'hosting', 'servers' => '2-5', 'team' => false], Plan::Pro],
]);
