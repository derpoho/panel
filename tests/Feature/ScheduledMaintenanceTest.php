<?php

use App\Enums\Plan;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\MaintenanceWindow;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceStatusPage;
use App\Support\Maintenance\ScheduledMaintenance;
use App\Support\StatusPages\FleetStatusReport;
use App\Support\StatusPages\StatusReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A Pro workspace with an online machine called web-1, a public status page
 * for it, and a gateway that accepts every job.
 *
 * @return array{0: Workspace, 1: Machine, 2: StatusPage}
 */
function plannedSetup(): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create(['name' => 'Acme']);
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'environment' => 'production']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'connected_at' => now()->subDay()])->save();
    $page = StatusPage::query()->forceCreate(['machine_id' => $machine->id, 'slug' => StatusPage::query()->exists() ? 'acme-'.$machine->id : 'acme', 'enabled' => true, 'public' => true, 'include_new' => true]);

    return [$workspace, $machine, $page];
}

/** Move the clock, with the machine still reporting as it would. */
function plannedTravel(Machine $machine, DateTimeInterface $to): void
{
    test()->travelTo($to);
    $machine->forceFill(['last_seen_at' => now()])->save();
}

function plannedUrl(Workspace $workspace, string $path): string
{
    return 'https://'.$workspace->host().$path;
}

/** Plan a window from the panel; returns it. */
function planMaintenance(Workspace $workspace, Machine $machine, array $fields = []): MaintenanceWindow
{
    test()->actingAs($workspace->owner)->post(plannedUrl($workspace, "/machines/{$machine->id}/maintenance/scheduled"), [
        'starts_at' => now()->addDays(2)->setTime(4, 0)->toIso8601String(),
        'minutes' => 30,
        'reason' => 'Kernel update',
        'public' => true,
        'action' => 'none',
        ...$fields,
    ])->assertRedirect()->assertSessionHasNoErrors();

    return MaintenanceWindow::query()->latest('id')->firstOrFail();
}

test('planned maintenance shows on the status page and in status.json from the moment it is planned', function () {
    [$workspace, $machine, $page] = plannedSetup();

    $window = planMaintenance($workspace, $machine);
    expect($window->state)->toBe('scheduled')
        ->and($window->minutes())->toBe(30)
        ->and($window->created_by)->toBe($workspace->owner_id)
        ->and(Activity::query()->where('action', 'machine.maintenance_scheduled')->exists())->toBeTrue();

    $report = (new StatusReport($page->fresh()))->toArray();
    expect($report['upcoming'])->toHaveCount(1)
        ->and($report['upcoming'][0])->toMatchArray(['reason' => 'Kernel update', 'machine' => null])
        ->and($report['notice'])->toBeNull();

    $this->getJson('/status/acme/status.json')->assertOk()
        ->assertJsonPath('scheduled_maintenance.0.reason', 'Kernel update')
        ->assertJsonPath('scheduled_maintenance.0.starts_at', $window->starts_at->toIso8601String());

    // Kept off the page when it isn't public.
    planMaintenance($workspace, $machine, ['starts_at' => now()->addDays(5)->toIso8601String(), 'public' => false, 'reason' => 'Quiet one']);
    expect((new StatusReport($page->fresh()))->toArray()['upcoming'])->toHaveCount(1);
});

test('windows can’t overlap, and must be in the future and within 60 days', function () {
    [$workspace, $machine] = plannedSetup();
    $first = planMaintenance($workspace, $machine);
    $url = plannedUrl($workspace, "/machines/{$machine->id}/maintenance/scheduled");
    $base = ['minutes' => 60, 'public' => true, 'action' => 'none'];

    $this->post($url, [...$base, 'starts_at' => $first->starts_at->copy()->addMinutes(10)->toIso8601String()])->assertSessionHasErrors('starts_at');
    $this->post($url, [...$base, 'starts_at' => now()->subHour()->toIso8601String()])->assertSessionHasErrors('starts_at');
    $this->post($url, [...$base, 'starts_at' => now()->addDays(61)->toIso8601String()])->assertSessionHasErrors('starts_at');
    $this->post($url, [...$base, 'starts_at' => now()->addDay()->toIso8601String(), 'action' => 'format-disk'])->assertSessionHasErrors('action');
    expect(MaintenanceWindow::query()->count())->toBe(1);
});

test('it starts on time, holds alerts, and the status page says when it ends; with no work it runs its full time', function () {
    [$workspace, $machine, $page] = plannedSetup();
    $window = planMaintenance($workspace, $machine);

    $this->travelTo($window->starts_at->copy()->addSeconds(10));
    app(ScheduledMaintenance::class)->tick();

    $machine->refresh();
    expect($window->fresh()->state)->toBe('active')
        ->and($machine->inMaintenance())->toBeTrue()
        ->and($machine->maintenance_until->equalTo($window->ends_at))->toBeTrue()
        ->and(DaemonJob::query()->count())->toBe(0);

    $notice = (new StatusReport($page->fresh()))->toArray()['notice'];
    expect($notice)->toMatchArray(['kind' => 'maintenance', 'reason' => 'Kernel update', 'until' => $window->ends_at->toIso8601String()]);

    $this->travelTo($window->ends_at->copy()->addMinute());
    app(ScheduledMaintenance::class)->tick();
    expect($window->fresh()->state)->toBe('done')
        ->and($machine->fresh()->inMaintenance())->toBeFalse();
});

test('a planned restart runs when it starts, and maintenance ends early once the machine is back', function () {
    [$workspace, $machine] = plannedSetup();
    $window = planMaintenance($workspace, $machine, ['action' => 'reboot', 'minutes' => 60]);

    plannedTravel($machine, $window->starts_at->copy()->addSeconds(5));
    app(ScheduledMaintenance::class)->tick();

    $job = DaemonJob::query()->sole();
    expect($job->kind)->toBe('reboot')
        ->and($job->user_id)->toBe($workspace->owner_id)
        ->and($window->fresh()->phase)->toBe('rebooting');

    // Still the old connection: not back yet.
    $this->travel(30)->seconds();
    app(ScheduledMaintenance::class)->tick();
    expect($window->fresh()->state)->toBe('active');

    // The daemon says hello again after the restart.
    $this->travel(40)->seconds();
    $machine->forceFill(['connected_at' => now(), 'last_seen_at' => now()])->save();
    app(ScheduledMaintenance::class)->tick();

    $window->refresh();
    expect($window->state)->toBe('done')
        ->and($window->phase)->toBe('back')
        ->and($window->outcome)->toStartWith('Back after a restart of')
        ->and($machine->fresh()->inMaintenance())->toBeFalse()
        ->and(Activity::query()->where('action', 'machine.maintenance_done')->exists())->toBeTrue();
});

test('planned updates restart only when they need to', function () {
    [$workspace, $machine] = plannedSetup();
    $window = planMaintenance($workspace, $machine, ['action' => 'updates']);

    plannedTravel($machine, $window->starts_at->copy()->addSeconds(5));
    app(ScheduledMaintenance::class)->tick();
    $updates = DaemonJob::query()->sole();
    expect($updates->kind)->toBe('package_updates')
        ->and($updates->command['job']['apply'])->toBeTrue();

    // Done, and the kernel wants a restart: that's next.
    $updates->forceFill(['state' => 'succeeded', 'result' => ['reboot_required' => true, 'applied' => true]])->save();
    app(ScheduledMaintenance::class)->tick();
    expect(DaemonJob::query()->where('kind', 'reboot')->exists())->toBeTrue()
        ->and($window->fresh()->phase)->toBe('rebooting');

    // Another machine whose updates need no restart finishes there.
    [$other, $box] = plannedSetup();
    $second = planMaintenance($other, $box, ['action' => 'updates']);
    plannedTravel($box, $second->starts_at->copy()->addSeconds(5));
    app(ScheduledMaintenance::class)->tick();
    DaemonJob::query()->where('machine_id', $box->id)->sole()->forceFill(['state' => 'succeeded', 'result' => ['reboot_required' => false]])->save();
    app(ScheduledMaintenance::class)->tick();

    expect($second->fresh()->state)->toBe('done')
        ->and($second->fresh()->outcome)->toBe('Updates installed. No restart was needed.')
        ->and($box->fresh()->inMaintenance())->toBeFalse();
});

test('a machine that isn’t reporting when it starts gets the window, but nothing is run', function () {
    [$workspace, $machine] = plannedSetup();
    $window = planMaintenance($workspace, $machine, ['action' => 'reboot']);
    $this->travelTo($window->starts_at->copy()->addSeconds(5));
    $machine->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();

    app(ScheduledMaintenance::class)->tick();

    expect(DaemonJob::query()->count())->toBe(0)
        ->and($window->fresh()->state)->toBe('active')
        ->and($window->fresh()->outcome)->toContain('wasn’t reporting');
});

test('calling it off takes it off the status page, and ending one under way ends maintenance', function () {
    [$workspace, $machine, $page] = plannedSetup();
    $window = planMaintenance($workspace, $machine);

    $this->delete(plannedUrl($workspace, "/machines/{$machine->id}/maintenance/scheduled/{$window->id}"))->assertRedirect();
    expect($window->fresh()->state)->toBe('cancelled')
        ->and((new StatusReport($page->fresh()))->toArray()['upcoming'])->toBe([]);

    $live = planMaintenance($workspace, $machine);
    $this->travelTo($live->starts_at->copy()->addSeconds(5));
    app(ScheduledMaintenance::class)->tick();
    expect($machine->fresh()->inMaintenance())->toBeTrue();

    $this->delete(plannedUrl($workspace, "/machines/{$machine->id}/maintenance/scheduled/{$live->id}"))->assertRedirect();
    expect($live->fresh()->state)->toBe('cancelled')
        ->and($machine->fresh()->inMaintenance())->toBeFalse();

    // Viewers can't plan or call off.
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)->post(plannedUrl($workspace, "/machines/{$machine->id}/maintenance/scheduled"), ['starts_at' => now()->addDay()->toIso8601String(), 'minutes' => 30, 'public' => true, 'action' => 'none'])->assertForbidden();
});

test('the dialog suggests the quietest hour of the week from the machine’s CPU history', function () {
    [$workspace, $machine] = plannedSetup();
    $this->travelTo(now()->startOfWeek()->addDays(2)->setTime(12, 0));

    // Two weeks of samples: busy, except Sundays at 03:00 UTC.
    $rows = [];
    for ($t = now()->subDays(14)->getTimestamp(); $t < now()->getTimestamp(); $t += 1800) {
        $at = now()->setTimestamp($t)->utc();
        $rows[] = ['machine_id' => $machine->id, 'ts' => $t, 'samples' => 6, 'cpu' => $at->dayOfWeekIso === 7 && $at->hour === 3 ? 2.5 : 40.0];
    }
    DB::table('machine_samples')->insert($rows);

    $this->actingAs($workspace->owner);
    $quiet = $this->getJson(plannedUrl($workspace, "/machines/{$machine->id}/maintenance/quiet-time"))->assertOk()->json('quiet');
    $at = now()->parse($quiet['startsAt'])->utc();
    expect($at->dayOfWeekIso)->toBe(7)
        ->and($at->hour)->toBe(3)
        ->and($at->isFuture())->toBeTrue()
        ->and($quiet['cpu'])->toBe(2.5)
        ->and($quiet['days'])->toBeGreaterThanOrEqual(13);

    // Not enough history: no suggestion.
    DB::table('machine_samples')->delete();
    $this->getJson(plannedUrl($workspace, "/machines/{$machine->id}/maintenance/quiet-time"))->assertJsonPath('quiet', null);
});

test('AI apps plan and call off maintenance through MCP', function () {
    [$workspace, $machine] = plannedSetup();
    $key = mcpKey($workspace);

    $planned = mcpCall($workspace, $key, 'start_maintenance', [
        'machine' => 'web-1',
        'minutes' => 30,
        'reason' => 'Kernel update',
        'starts_at' => now()->addDay()->toIso8601String(),
        'action' => 'reboot',
    ]);
    expect($planned['error'])->toBeFalse()
        ->and($planned['data']['planned']['action'])->toBe('reboot')
        ->and($planned['data']['planned']['state'])->toBe('scheduled')
        ->and($machine->fresh()->inMaintenance())->toBeFalse();

    $id = $planned['data']['planned']['id'];
    expect(mcpCall($workspace, $key, 'get_machine', ['machine' => 'web-1'])['data']['plannedMaintenance'][0]['id'])->toBe($id);

    $off = mcpCall($workspace, $key, 'end_maintenance', ['machine' => 'web-1', 'planned' => $id]);
    expect($off['data']['calledOff'])->toBeTrue()
        ->and(MaintenanceWindow::query()->find($id)->state)->toBe('cancelled');

    expect(mcpCall($workspace, $key, 'start_maintenance', ['machine' => 'web-1', 'starts_at' => now()->subDay()->toIso8601String()])['error'])->toBeTrue();
});

test('a fleet status page lists planned maintenance with each machine’s name', function () {
    [$workspace, $machine] = plannedSetup();
    $db = Machine::factory()->for($workspace)->create(['name' => 'db-1']);
    $db->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()])->save();
    $fleet = WorkspaceStatusPage::query()->forceCreate(['workspace_id' => $workspace->id, 'slug' => 'acme-all', 'title' => 'Acme', 'enabled' => true, 'public' => true]);

    planMaintenance($workspace, $db, ['reason' => 'Postgres 17']);

    $upcoming = (new FleetStatusReport($fleet->fresh()))->toArray()['upcoming'];
    expect($upcoming)->toHaveCount(1)
        ->and($upcoming[0])->toMatchArray(['reason' => 'Postgres 17', 'machine' => 'db-1']);
});
