<?php

use App\Enums\Plan;
use App\Models\Activity;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Deploys\AppReleases;
use App\Support\Deploys\ServiceLabels;
use App\Support\Health\WorkspaceHealth;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A machine with a running app, two old stopped Pterodactyl containers
 * that stopped on their own, and a system unit.
 *
 * @return array{0: Workspace, 1: Machine, 2: string}
 */
function hiddenMachine(): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'environment' => 'production']);
    $service = fn ($key, $name, $status) => ['key' => $key, 'name' => $name, 'kind' => 'container', 'manager' => 'docker', 'status' => $status, 'ports' => [], 'confidence' => 90, 'details' => []];
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'services_count' => 4,
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
            $service('docker:aaa111aaa111', 'shop', 'running'),
            $service('docker:bbb222bbb222', '8232c33a-0769-42d9', 'exited'),
            $service('docker:ccc333ccc333', 'f05ce976-1d2e-4f5a', 'exited'),
            $service('systemd:multipathd.service', 'multipathd', 'running'),
        ]],
        'alert_state' => ['services' => ['down' => [
            'docker:bbb222bbb222' => now()->subHour()->getTimestamp(),
            'docker:ccc333ccc333' => now()->subHour()->getTimestamp(),
        ]]],
    ])->save();

    return [$workspace, $machine, 'https://'.$workspace->host().'/machines/'.$machine->id];
}

test('services are hidden and shown again in bulk, without touching the machine', function () {
    [$workspace, $machine, $url] = hiddenMachine();

    $this->actingAs($workspace->owner)
        ->put($url.'/hidden-services', ['services' => ['docker:bbb222bbb222', 'docker:ccc333ccc333', 'systemd:multipathd.service'], 'hidden' => true])
        ->assertRedirect();

    $machine->refresh();
    expect($machine->hidden_services)->toBe(['container:8232c33a-0769-42d9', 'container:f05ce976-1d2e-4f5a', 'systemd:multipathd.service'])
        // Home counts what's shown.
        ->and($machine->services_count)->toBe(1)
        ->and(Activity::query()->latest('id')->first()->only('action', 'subject'))->toBe(['action' => 'service.hide', 'subject' => '3 services']);

    // The machine page still has them, marked, for the "Show hidden" toggle.
    $this->actingAs($workspace->owner)->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('machine.inventory.services.0.hidden', false)
        ->where('machine.inventory.services.1.hidden', true)
        ->etc());

    $this->actingAs($workspace->owner)
        ->put($url.'/hidden-services', ['services' => ['systemd:multipathd.service'], 'hidden' => false])
        ->assertRedirect();
    expect($machine->fresh()->services_count)->toBe(2)
        ->and(Activity::query()->latest('id')->first()->only('action', 'subject'))->toBe(['action' => 'service.unhide', 'subject' => 'multipathd']);
});

test('a hidden container stays hidden when it is recreated with a new id, and the next scan keeps the count', function () {
    [, $machine] = hiddenMachine();
    ServiceLabels::setHidden($machine, [$machine->inventory['services'][1]], true);

    $inventory = $machine->inventory;
    $inventory['services'][1]['key'] = 'docker:ddd444ddd444';

    expect(AppReleases::visible($machine, $inventory['services'])[1]['hidden'])->toBeTrue()
        ->and(ServiceLabels::shownCount($machine, $inventory['services']))->toBe(3);
});

test('hidden services are not problems, and are left off status pages', function () {
    [$workspace, $machine] = hiddenMachine();
    $titles = fn () => array_column(app(WorkspaceHealth::class)->issues($workspace, $workspace->machines()->get()), 'title');

    expect($titles())->toContain('8232c33a-0769-42d9 stopped on web-1');

    ServiceLabels::setHidden($machine, [$machine->inventory['services'][1], $machine->inventory['services'][2]], true);
    expect(implode(' ', $titles()))->not->toContain('stopped on web-1');

    $this->actingAs($workspace->owner)->put('https://'.$workspace->host().'/machines/'.$machine->id.'/status-page', [
        'enabled' => true, 'public' => true, 'slug' => 'web-one', 'title' => 'Acme status', 'description' => null,
        'include_new' => true, 'show_uptime' => false, 'notice' => null, 'notice_kind' => null, 'services' => [],
    ])->assertSessionHasNoErrors();
    auth()->logout();

    $this->getJson('/status/web-one/status.json')->assertOk()->assertJsonCount(2, 'components');
});

test('viewers cannot hide services, and unknown services are not found', function () {
    [$workspace, , $url] = hiddenMachine();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    $this->actingAs($viewer)->put($url.'/hidden-services', ['services' => ['docker:aaa111aaa111'], 'hidden' => true])->assertForbidden();
    $this->actingAs($workspace->owner)->put($url.'/hidden-services', ['services' => ['docker:nope'], 'hidden' => true])->assertNotFound();
});
