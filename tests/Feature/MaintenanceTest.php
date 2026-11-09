<?php

use App\Enums\Plan;
use App\Models\Activity;
use App\Models\Machine;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\WorkflowRun;
use App\Models\Workspace;
use App\Notifications\MonitorAlert;
use App\Notifications\WeeklySummaryMail;
use App\Support\Health\MachineWatcher;
use App\Support\StatusPages\StatusReport;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => Notification::fake());

/**
 * @return array{0: Workspace, 1: Machine}
 */
function maintenanceMachine(): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create(['name' => 'Acme']);
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'environment' => 'production']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()])->save();

    return [$workspace, $machine];
}

function on(Workspace $workspace, string $path): string
{
    return 'https://'.$workspace->host().$path;
}

test('maintenance holds alerts and workflows, and what is still wrong afterwards is alerted', function () {
    [$workspace, $machine] = maintenanceMachine();
    $workspace->workflows()->create([
        'name' => 'Recover', 'enabled' => true, 'trigger' => 'machine.offline', 'run_as' => $workspace->owner_id,
        'graph' => ['nodes' => [['id' => 't', 'kind' => 'trigger', 'x' => 0, 'y' => 0, 'title' => '', 'description' => '', 'rules' => ['event' => 'machine.offline']]], 'edges' => []],
    ]);

    $this->actingAs($workspace->owner)->post(on($workspace, "/machines/{$machine->id}/maintenance"), ['minutes' => 60, 'reason' => 'Kernel upgrade', 'public' => true])
        ->assertRedirect();
    expect($machine->fresh()->inMaintenance())->toBeTrue();

    // It reboots: quiet for 10 minutes, then a full disk.
    $machine->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();
    expect(app(MachineWatcher::class)->run())->toBe(0);
    Notification::assertNothingSent();
    expect(WorkflowRun::query()->count())->toBe(0);

    // Home says so, quietly.
    $this->actingAs($workspace->owner)->get(on($workspace, '/'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('issues', fn ($issues) => collect($issues)->contains(fn ($i) => str_starts_with($i['title'], 'web-1 is in maintenance until')))
            ->where('issues', fn ($issues) => collect($issues)->every(fn ($i) => $i['severity'] === 'notice')));

    // The window ends and it's still down: now it's news.
    $this->travel(61)->minutes();
    expect(app(MachineWatcher::class)->run())->toBe(1);
    Notification::assertSentTo($workspace->owner, MonitorAlert::class, fn (MonitorAlert $n) => $n->subject === 'web-1 stopped reporting');

    expect(Activity::query()->pluck('action')->all())->toContain('machine.maintenance', 'machine.offline');
});

test('a status page shows planned maintenance instead of an outage', function () {
    [, $machine] = maintenanceMachine();
    $page = StatusPage::query()->forceCreate(['machine_id' => $machine->id, 'slug' => 'acme', 'enabled' => true]);
    $machine->forceFill(['last_seen_at' => now()->subMinutes(10), 'maintenance_until' => now()->addHour(), 'maintenance_reason' => 'Upgrading Postgres', 'maintenance_public' => true])->save();

    $report = (new StatusReport($page->fresh()))->toArray();
    expect($report['overall'])->toBe(['status' => 'degraded', 'label' => 'Under maintenance'])
        ->and($report['notice']['kind'])->toBe('maintenance')
        ->and($report['notice']['text'])->toStartWith('Upgrading Postgres. Expected to finish by');
});

test('ending maintenance early, and muting a monitor', function () {
    [$workspace, $machine] = maintenanceMachine();
    $machine->forceFill(['maintenance_until' => now()->addHour()])->save();
    $monitor = $workspace->monitors()->create(['name' => 'Shop', 'type' => 'http', 'url' => 'https://shop.example.com', 'interval_seconds' => 60, 'timeout_seconds' => 10, 'status' => 'up']);

    $this->actingAs($workspace->owner)->delete(on($workspace, "/machines/{$machine->id}/maintenance"))->assertRedirect();
    expect($machine->fresh()->inMaintenance())->toBeFalse();

    $this->actingAs($workspace->owner)->post(on($workspace, "/monitors/{$monitor->id}/mute"), ['minutes' => 240])->assertRedirect();
    expect($monitor->fresh()->muted())->toBeTrue();
    $this->actingAs($workspace->owner)->post(on($workspace, "/monitors/{$monitor->id}/mute"), ['minutes' => 7])->assertSessionHasErrors('minutes');

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)->post(on($workspace, "/machines/{$machine->id}/maintenance"), ['minutes' => 60, 'public' => true])->assertForbidden();
});

test('each person chooses which alert emails they get', function () {
    [$workspace, $machine] = maintenanceMachine();
    $admin = User::factory()->create();
    $workspace->members()->attach($admin, ['role' => 'admin']);

    $this->actingAs($admin)->put(on($workspace, '/settings/notifications'), ['alert_emails' => 'problems', 'weekly_summary' => false])->assertRedirect();
    $this->actingAs($workspace->owner)->put(on($workspace, '/settings/notifications'), ['alert_emails' => 'none', 'weekly_summary' => true]);

    $machine->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();
    app(MachineWatcher::class)->run();
    $machine->forceFill(['last_seen_at' => now()])->save();
    app(MachineWatcher::class)->run();

    // The admin hears it went down, not that it's back; the owner hears nothing.
    Notification::assertSentTo($admin, MonitorAlert::class, fn ($n) => $n->subject === 'web-1 stopped reporting');
    Notification::assertNotSentTo($admin, MonitorAlert::class, fn ($n) => $n->subject === 'web-1 is reporting again');
    Notification::assertNotSentTo($workspace->owner, MonitorAlert::class);

    $this->actingAs($admin)->get(on($workspace, '/settings/profile'))
        ->assertInertia(fn (Assert $page) => $page->where('notifications', ['alertEmails' => 'problems', 'weeklySummary' => false, 'getsAlerts' => true]));
});

test('the weekly summary goes once a week to those who want it', function () {
    [$workspace] = maintenanceMachine();
    $quiet = User::factory()->create();
    $workspace->members()->attach($quiet, ['role' => 'member', 'weekly_summary' => false]);
    $workspace->monitors()->create(['name' => 'Shop', 'type' => 'http', 'url' => 'https://shop.example.com', 'interval_seconds' => 60, 'timeout_seconds' => 10, 'status' => 'up']);

    $this->artisan('summaries:send')->assertSuccessful();
    Notification::assertSentTo($workspace->owner, WeeklySummaryMail::class, fn (WeeklySummaryMail $n) => $n->summary['headline'] === 'No downtime this week'
        && collect($n->summary['sections'])->pluck('title')->all() === ['Uptime', 'This week', 'Needs attention']);
    Notification::assertNotSentTo($quiet, WeeklySummaryMail::class);

    $this->artisan('summaries:send');
    Notification::assertSentTimes(WeeklySummaryMail::class, 1);
});

test('search finds machines, services, monitors and workflows in the workspace only', function () {
    [$workspace, $machine] = maintenanceMachine();
    $machine->forceFill(['inventory' => ['services' => [['key' => 'docker:abc', 'name' => 'serveros-umami-umami-1', 'status' => 'running']]]])->save();
    $workspace->monitors()->create(['name' => 'Umami site', 'type' => 'http', 'url' => 'https://umami.example.com', 'interval_seconds' => 60, 'timeout_seconds' => 10, 'status' => 'up']);
    $workspace->workflows()->create(['name' => 'Redeploy umami', 'graph' => ['nodes' => [], 'edges' => []]]);
    [$other] = maintenanceMachine();

    $this->actingAs($workspace->owner)->getJson(on($workspace, '/search?q=umami'))
        ->assertOk()
        ->assertJsonPath('services.0.label', 'Umami')
        ->assertJsonPath('services.0.url', "/machines/{$machine->id}/services/docker:abc")
        ->assertJsonPath('monitors.0.label', 'Umami site')
        ->assertJsonPath('workflows.0.label', 'Redeploy umami');

    $this->actingAs($workspace->owner)->getJson(on($workspace, '/search?q=web'))->assertJsonCount(1, 'machines');
    $this->actingAs($other->owner)->getJson(on($other, '/search?q=umami'))->assertJsonCount(0, 'services')->assertJsonCount(0, 'workflows');
});
