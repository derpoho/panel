<?php

use App\Enums\Plan;
use App\Models\Activity;
use App\Models\Machine;
use App\Models\StatusIncident;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceStatusPage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A Business workspace with one healthy machine that has a live status
 * page at /status/web-one, and a live fleet page at /status/acme.
 *
 * @return array{0: Workspace, 1: Machine, 2: string}
 */
function incidentsSetup(): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'Web One']);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
            ['key' => 'docker:aaa', 'name' => 'shop', 'kind' => 'app', 'manager' => 'docker', 'status' => 'running', 'ports' => [], 'confidence' => 90],
        ]],
    ])->save();
    $page = new StatusPage(['slug' => 'web-one', 'enabled' => true, 'public' => true, 'show_uptime' => true, 'include_new' => true, 'services' => []]);
    $page->machine_id = $machine->id;
    $page->save();
    $fleet = new WorkspaceStatusPage(['slug' => 'acme', 'enabled' => true, 'public' => true, 'show_uptime' => true]);
    $fleet->workspace_id = $workspace->id;
    $fleet->save();

    return [$workspace, $machine, 'https://'.$workspace->host()];
}

test('an incident is posted, updated and resolved, and visitors follow it', function () {
    [$workspace, $machine, $base] = incidentsSetup();

    $this->actingAs($workspace->owner)->post("{$base}/status-incidents", [
        'title' => 'Checkout is failing', 'impact' => 'major', 'state' => 'investigating',
        'message' => 'We’re looking into failed payments.', 'machine_id' => $machine->id,
    ])->assertSessionHasNoErrors();
    $incident = StatusIncident::query()->sole();
    expect(Activity::query()->latest('id')->first()->action)->toBe('status_page.incident_open');

    // Visitors see it, and it outranks the green checks.
    auth()->logout();
    foreach (['/status/web-one', '/status/acme'] as $url) {
        $this->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('status.overall.label', 'Major outage')
            ->has('status.notices', 1)
            ->where('status.notices.0.title', 'Checkout is failing')
            ->where('status.notices.0.resolvedAt', null)
            ->where('status.notices.0.updates.0.message', 'We’re looking into failed payments.'));
    }

    $this->actingAs($workspace->owner)->post("{$base}/status-incidents/{$incident->id}/updates", [
        'state' => 'identified', 'message' => 'A bad deploy. Rolling it back.', 'impact' => 'partial',
    ])->assertSessionHasNoErrors();
    $this->actingAs($workspace->owner)->post("{$base}/status-incidents/{$incident->id}/updates", [
        'state' => 'resolved', 'message' => 'Payments work again.',
    ])->assertSessionHasNoErrors();

    expect($incident->fresh())->state->toBe('resolved')->impact->toBe('partial')->resolved_at->not->toBeNull()
        ->and(Activity::query()->latest('id')->first()->action)->toBe('status_page.incident_resolve');

    auth()->logout();
    $this->get('/status/web-one')->assertInertia(fn (Assert $page) => $page
        ->where('status.overall.label', 'All systems operational')
        ->where('status.notices.0.state', 'resolved')
        ->has('status.notices.0.updates', 3)
        ->where('status.notices.0.updates.0.message', 'Payments work again.'));
    $this->getJson('/status/web-one/status.json')
        ->assertJsonPath('notices.0.title', 'Checkout is failing')
        ->assertJsonPath('notices.0.updates.2.state', 'investigating');

    // Two weeks on, it's gone from the page.
    $this->travel(StatusIncident::KEEP_DAYS + 1)->days();
    $this->get('/status/web-one')->assertInertia(fn (Assert $page) => $page->has('status.notices', 0));
});

test('an incident about nothing in particular shows on the workspace page only', function () {
    [$workspace, , $base] = incidentsSetup();

    $this->actingAs($workspace->owner)->post("{$base}/status-incidents", [
        'title' => 'Slow emails', 'impact' => 'degraded', 'state' => 'monitoring', 'message' => 'Emails are arriving late.',
    ])->assertSessionHasNoErrors();

    auth()->logout();
    $this->get('/status/acme')->assertInertia(fn (Assert $page) => $page
        ->where('status.overall.label', 'Degraded performance')
        ->where('status.notices.0.title', 'Slow emails'));
    $this->get('/status/web-one')->assertInertia(fn (Assert $page) => $page
        ->where('status.overall.label', 'All systems operational')
        ->has('status.notices', 0));
});

test('the monitors page lists incidents, and only people who manage machines can post or delete them', function () {
    [$workspace, $machine, $base] = incidentsSetup();
    $other = Machine::factory()->for(Workspace::factory())->create();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $payload = ['title' => 'Down', 'impact' => 'major', 'state' => 'investigating', 'message' => 'Looking.'];

    $this->actingAs($viewer)->post("{$base}/status-incidents", $payload)->assertForbidden();
    $this->actingAs($workspace->owner)->post("{$base}/status-incidents", [...$payload, 'machine_id' => $other->id])->assertSessionHasErrors('machine_id');
    $this->actingAs($workspace->owner)->post("{$base}/status-incidents", [...$payload, 'state' => 'resolved'])->assertSessionHasErrors('state');
    $this->actingAs($workspace->owner)->post("{$base}/status-incidents", $payload)->assertSessionHasNoErrors();
    $incident = StatusIncident::query()->sole();

    // Managed from the Monitors page, beside the monitors.
    $this->actingAs($workspace->owner)->get("{$base}/monitors")
        ->assertInertia(fn (Assert $page) => $page->component('workspace/Monitors')->has('incidents', 1)->where('incidents.0.title', 'Down')->where('incidents.0.machineId', null));

    $this->actingAs($viewer)->delete("{$base}/status-incidents/{$incident->id}")->assertForbidden();
    // Another workspace's incidents are out of reach.
    $theirs = StatusIncident::query()->create(['workspace_id' => $other->workspace_id, 'title' => 'Theirs', 'impact' => 'major', 'state' => 'investigating', 'started_at' => now()]);
    $this->actingAs($workspace->owner)->delete("{$base}/status-incidents/{$theirs->id}")->assertNotFound();

    $this->actingAs($workspace->owner)->delete("{$base}/status-incidents/{$incident->id}")->assertRedirect();
    expect(StatusIncident::query()->whereKey($incident->id)->exists())->toBeFalse()
        ->and(Activity::query()->latest('id')->first()->action)->toBe('status_page.incident_delete');
});
