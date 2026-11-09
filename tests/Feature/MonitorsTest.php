<?php

use App\Enums\Plan;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Workspace;
use App\Notifications\MonitorAlert;
use App\Support\Health\MachineWatcher;
use App\Support\Monitors\HostResolver;
use App\Support\Monitors\MonitorRunner;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set('monitors.check_certificates', false);

    // Public names resolve to a public address; anything "internal" or a
    // private IP doesn't, as the real resolver would refuse it.
    app()->instance(HostResolver::class, new class extends HostResolver
    {
        public function resolve(string $host): array
        {
            if (str_contains($host, 'internal') || str_contains($host, 'nowhere')) {
                return [];
            }
            if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
                return $this->allowed(trim($host, '[]')) ? [trim($host, '[]')] : [];
            }

            return ['93.184.215.14'];
        }
    });
});

function monitorWorkspace(?Plan $plan = Plan::Pro): Workspace
{
    $factory = Workspace::factory();

    return ($plan ? $factory->onPlan($plan) : $factory->trialExpired())->create();
}

function monitorsUrl(Workspace $workspace, string $path = '/monitors'): string
{
    return 'https://'.$workspace->host().$path;
}

/** @param  array<string, mixed>  $overrides */
function monitorPayload(array $overrides = []): array
{
    return [
        'name' => 'Shop', 'type' => 'http', 'url' => 'https://shop.example.com/', 'host' => null, 'port' => null,
        'interval' => 60, 'timeout' => 10, 'expected_status' => null, 'keyword' => null, 'machine_id' => null, 'alerts' => true,
        ...$overrides,
    ];
}

function makeMonitor(Workspace $workspace, array $overrides = []): Monitor
{
    return $workspace->monitors()->create([
        'name' => 'Shop', 'type' => 'http', 'url' => 'https://shop.example.com/',
        'interval_seconds' => 60, 'timeout_seconds' => 10, 'status' => 'pending', 'alerts' => true,
        ...$overrides,
    ]);
}

function runMonitor(Monitor $monitor): Monitor
{
    app(MonitorRunner::class)->run(collect([$monitor->fresh('workspace')]));

    return $monitor->fresh();
}

test('a monitor is created, and its address is checked first', function () {
    $workspace = monitorWorkspace();
    Http::fake(['*' => Http::response('ok')]);

    $this->actingAs($workspace->owner)->post(monitorsUrl($workspace), monitorPayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $monitor = Monitor::query()->sole();
    expect($monitor->only(['name', 'type', 'url', 'interval_seconds']))
        ->toBe(['name' => 'Shop', 'type' => 'http', 'url' => 'https://shop.example.com/', 'interval_seconds' => 60]);

    $this->actingAs($workspace->owner)->post(monitorsUrl($workspace), monitorPayload(['url' => 'http://10.0.0.5/']))
        ->assertSessionHasErrors(['url' => 'Private and internal addresses can’t be monitored from the panel.']);
    $this->actingAs($workspace->owner)->post(monitorsUrl($workspace), monitorPayload(['url' => 'https://nowhere.example/']))
        ->assertSessionHasErrors(['url' => 'Couldn’t find nowhere.example in DNS.']);
    $this->actingAs($workspace->owner)->post(monitorsUrl($workspace), monitorPayload(['type' => 'tcp', 'host' => '127.0.0.1', 'port' => 5432]))
        ->assertSessionHasErrors('host');
    $this->actingAs($workspace->owner)->post(monitorsUrl($workspace), monitorPayload(['url' => 'ftp://shop.example.com']))
        ->assertSessionHasErrors('url');
});

test('the plan sets how many monitors and how often they check', function () {
    $workspace = monitorWorkspace(Plan::Essential);

    $this->actingAs($workspace->owner)->post(monitorsUrl($workspace), monitorPayload(['interval' => 60]))
        ->assertSessionHasErrors(['interval' => 'Your plan checks at most every 3 minutes.']);

    foreach (range(1, 10) as $i) {
        makeMonitor($workspace, ['name' => "M{$i}"]);
    }
    $this->actingAs($workspace->owner)->post(monitorsUrl($workspace), monitorPayload(['interval' => 180]))
        ->assertSessionHasErrors('name');

    $none = monitorWorkspace(null);
    $this->actingAs($none->owner)->post(monitorsUrl($none), monitorPayload())
        ->assertSessionHasErrors(['name' => 'Choose a plan to add monitors.']);
});

test('two failures in a row take it down and alert; the next pass brings it back', function () {
    Notification::fake();
    $workspace = monitorWorkspace();
    $workspace->forceFill(['alert_webhook_url' => 'https://hooks.slack.com/services/T/B/x'])->save();
    $monitor = makeMonitor($workspace);
    $up = false;
    Http::fake(function (HttpRequest $request) use (&$up) {
        if (str_contains($request->url(), 'hooks.slack.com')) {
            return Http::response('ok');
        }

        return $up ? Http::response('fine') : Http::response('nope', 503);
    });

    $monitor = runMonitor($monitor);
    expect($monitor->status)->toBe('pending')
        ->and($monitor->failures)->toBe(1)
        ->and($monitor->last_error)->toBe('Returned 503.')
        ->and($monitor->incidents()->count())->toBe(0);
    Notification::assertNothingSent();

    $monitor = runMonitor($monitor);
    expect($monitor->status)->toBe('down')
        ->and($monitor->openIncident->cause)->toBe('Returned 503.');
    Notification::assertSentTo($workspace->owner, MonitorAlert::class, fn (MonitorAlert $n) => $n->subject === 'Shop is down');
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'hooks.slack.com') && $r['event'] === 'monitor.down' && str_contains($r['text'], 'Shop is down'));

    // Still down: no second alert.
    runMonitor($monitor);
    Notification::assertSentTimes(MonitorAlert::class, 1);

    $up = true;
    $monitor = runMonitor($monitor);
    expect($monitor->status)->toBe('up')
        ->and($monitor->openIncident)->toBeNull()
        ->and($monitor->incidents()->first()->resolved_at)->not->toBeNull()
        ->and($monitor->uptime(1))->toBe(25.0);
    Notification::assertSentTo($workspace->owner, MonitorAlert::class, fn (MonitorAlert $n) => $n->subject === 'Shop is back up');
    expect(MonitorCheck::query()->count())->toBe(4);
});

test('a keyword or an exact status can be required', function () {
    $workspace = monitorWorkspace();
    Http::fake(['*' => Http::response('<h1>Maintenance</h1>', 200)]);

    $keyword = runMonitor(makeMonitor($workspace, ['keyword' => 'Add to cart']));
    expect($keyword->last_error)->toBe('“Add to cart” isn’t on the page.');

    $exact = runMonitor(makeMonitor($workspace, ['expected_status' => 204]));
    expect($exact->last_error)->toBe('Returned 200, expected 204.');

    $plain = runMonitor(makeMonitor($workspace, ['keyword' => 'maintenance']));
    expect($plain->status)->toBe('up');
});

test('a port is checked by connecting to it', function () {
    config()->set('monitors.allow_private', true);
    $workspace = monitorWorkspace();
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

    $open = runMonitor(makeMonitor($workspace, ['type' => 'tcp', 'url' => null, 'host' => '127.0.0.1', 'port' => $port]));
    expect($open->status)->toBe('up');

    fclose($server);
    $closed = runMonitor(makeMonitor($workspace, ['type' => 'tcp', 'url' => null, 'host' => '127.0.0.1', 'port' => $port]));
    expect($closed->last_error)->toBe('Connection refused.');
});

test('paused monitors are not checked, and pausing closes an open incident', function () {
    $workspace = monitorWorkspace();
    $monitor = makeMonitor($workspace, ['status' => 'down', 'next_check_at' => now()->subMinute()]);
    $monitor->incidents()->create(['started_at' => now()->subMinutes(5), 'cause' => 'Timed out']);

    $this->actingAs($workspace->owner)->patch(monitorsUrl($workspace, "/monitors/{$monitor->id}/pause"), ['paused' => true])->assertRedirect();

    expect($monitor->fresh()->paused)->toBeTrue()
        ->and($monitor->fresh()->openIncident)->toBeNull()
        ->and(app(MonitorRunner::class)->runDue())->toBe(0);
});

test('only due monitors in workspaces with a plan are checked', function () {
    Http::fake(['*' => Http::response('ok')]);
    $due = makeMonitor(monitorWorkspace(), ['next_check_at' => now()->subSecond()]);
    makeMonitor(monitorWorkspace(), ['next_check_at' => now()->addMinute()]);
    makeMonitor(monitorWorkspace(null), ['next_check_at' => now()->subSecond()]);

    expect(app(MonitorRunner::class)->runDue())->toBe(1)
        ->and($due->fresh()->status)->toBe('up')
        ->and($due->fresh()->next_check_at->isFuture())->toBeTrue();
});

test('the monitors pages show status, uptime and history', function () {
    $workspace = monitorWorkspace();
    Http::fake(['*' => Http::response('ok')]);
    $monitor = runMonitor(makeMonitor($workspace));

    $this->actingAs($workspace->owner)->get(monitorsUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/Monitors')
            ->where('monitors.0.status', 'up')
            ->where('monitors.0.uptime30', 100)
            ->where('limit', 50)
            ->where('minInterval', 60));

    $this->actingAs($workspace->owner)->get(monitorsUrl($workspace, "/monitors/{$monitor->id}"))
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/MonitorShow')
            ->where('uptime.day', 100)
            ->has('chart.timestamps', 144)
            ->has('checks', 1)
            ->has('days', 90));

    $other = monitorWorkspace();
    $this->actingAs($other->owner)->get(monitorsUrl($other, "/monitors/{$monitor->id}"))->assertNotFound();
});

test('alerts go to a webhook that can be tested first', function () {
    $workspace = monitorWorkspace();
    Http::fake(['*' => Http::response('ok')]);

    $this->actingAs($workspace->owner)->put(monitorsUrl($workspace, '/monitor-alerts'), ['email' => false, 'webhook' => 'http://hooks.slack.com/x'])
        ->assertSessionHasErrors('webhook');
    $this->actingAs($workspace->owner)->put(monitorsUrl($workspace, '/monitor-alerts'), ['email' => false, 'webhook' => 'https://internal.example/hook'])
        ->assertSessionHasErrors('webhook');
    $this->actingAs($workspace->owner)->put(monitorsUrl($workspace, '/monitor-alerts'), ['email' => false, 'webhook' => 'https://hooks.slack.com/services/T/B/x'])
        ->assertSessionHasNoErrors();

    expect($workspace->fresh()->alert_email)->toBeFalse()
        ->and($workspace->fresh()->alert_webhook_url)->toBe('https://hooks.slack.com/services/T/B/x');

    $this->actingAs($workspace->owner)->postJson(monitorsUrl($workspace, '/monitor-alerts/test'), ['webhook' => 'https://hooks.slack.com/services/T/B/x'])
        ->assertJson(['ok' => true]);
    Http::assertSent(fn (HttpRequest $r) => $r['event'] === 'test');
});

test('a server that stops reporting or fills its disk alerts once, then again when it is over', function () {
    Notification::fake();
    $workspace = monitorWorkspace();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'environment' => 'production']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()->subMinutes(2)])->save();
    $watch = fn () => app(MachineWatcher::class)->run();

    // Quiet for two minutes: not yet.
    expect($watch())->toBe(0);

    // Nor when the daemon dropped its connection a moment ago, as it does
    // restarting into an update.
    $machine->forceFill(['last_seen_at' => now()->subSeconds(20), 'disconnected_at' => now()->subSeconds(15)])->save();
    expect($watch())->toBe(0);
    $machine->forceFill(['disconnected_at' => null])->save();

    $machine->forceFill(['last_seen_at' => now()->subMinutes(6)])->save();
    expect($watch())->toBe(1)->and($watch())->toBe(0);
    Notification::assertSentTo($workspace->owner, MonitorAlert::class, fn (MonitorAlert $n) => $n->subject === 'web-1 stopped reporting');

    $machine->forceFill(['last_seen_at' => now(), 'disk_percent' => 93, 'disks' => [['mount' => '/', 'used' => 93 << 30, 'total' => 100 << 30]]])->save();
    expect($watch())->toBe(2);
    Notification::assertSentTo($workspace->owner, MonitorAlert::class, fn (MonitorAlert $n) => $n->subject === 'web-1 is reporting again');
    Notification::assertSentTo($workspace->owner, MonitorAlert::class, fn (MonitorAlert $n) => $n->subject === 'web-1’s disk is 93% full' && str_contains(implode(' ', $n->lines), '7 GB left'));

    // Hovering near the line doesn't repeat it; clearing and filling again does.
    $machine->forceFill(['disk_percent' => 88])->save();
    expect($watch())->toBe(0);
    $machine->forceFill(['disk_percent' => 70])->save();
    $watch();
    $machine->forceFill(['disk_percent' => 91])->save();
    expect($watch())->toBe(1);

    expect(Activity::query()->where('workspace_id', $workspace->id)->pluck('action')->all())
        ->toBe(['machine.offline', 'machine.online', 'machine.disk_full', 'machine.disk_full']);

    // A server long dead before anyone watched doesn't page anyone, and
    // isn't announced again later.
    $old = Machine::factory()->for($workspace)->create(['name' => 'retired', 'environment' => 'production']);
    $old->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()->subWeeks(3)])->save();
    $machine->forceFill(['disk_percent' => 91])->save();
    expect($watch())->toBe(0)->and($old->fresh()->alert_state)->toHaveKey('offline');

    // Development machines don't page anyone for going quiet.
    $machine->forceFill(['environment' => 'development', 'last_seen_at' => now()->subHour(), 'alert_state' => null])->save();
    expect($watch())->toBe(0);
});

test('a service that stops or keeps restarting alerts, but not one stopped on purpose or replaced', function () {
    Notification::fake();
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    $workspace = monitorWorkspace();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'environment' => 'production']);
    $svc = fn ($key, $name, $status) => ['key' => $key, 'name' => $name, 'kind' => 'app', 'manager' => 'docker', 'status' => $status, 'ports' => []];
    $set = function (array $services, array $stats) use ($machine) {
        $machine->forceFill(['uid' => $machine->uid ?? (string) Str::uuid(), 'last_seen_at' => now(),
            'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => $services],
            'service_stats' => $stats])->save();
    };
    $stat = fn (int $restarts = 0) => ['cpu' => 1, 'restarts' => $restarts, 'at' => now()->getTimestamp()];
    $watch = fn () => app(MachineWatcher::class)->run();

    $set([$svc('docker:aaa', 'shop', 'running'), $svc('docker:bbb', 'worker', 'running'), $svc('docker:ccc', 'cron', 'running')],
        ['docker:aaa' => $stat(), 'docker:bbb' => $stat(), 'docker:ccc' => $stat()]);
    expect($watch())->toBe(0);

    // shop drops out of telemetry: a scan is asked for, nothing said yet.
    $set([$svc('docker:aaa', 'shop', 'running'), $svc('docker:bbb', 'worker', 'running'), $svc('docker:ccc', 'cron', 'running')],
        ['docker:bbb' => $stat(), 'docker:ccc' => $stat()]);
    expect($watch())->toBe(0);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/commands') && $r['command']['job']['type'] === 'discover');

    // The scan says shop failed, worker was replaced by a redeploy, and cron
    // was stopped by a person.
    DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'user_id' => $workspace->owner_id,
        'kind' => 'service_action', 'service' => 'docker:ccc', 'state' => 'succeeded', 'command' => ['job' => ['type' => 'service_action', 'action' => 'stop']], 'log' => []]);
    $set([$svc('docker:aaa', 'shop', 'failed'), $svc('docker:ddd', 'worker', 'running'), $svc('docker:ccc', 'cron', 'stopped')],
        ['docker:ddd' => $stat()]);
    expect($watch())->toBe(1)->and($watch())->toBe(0);
    Notification::assertSentTo($workspace->owner, MonitorAlert::class, fn (MonitorAlert $n) => $n->subject === 'shop stopped on web-1');

    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/')
        ->assertInertia(fn ($page) => $page->where('issues.0.title', 'shop stopped on web-1')->where('issues.0.action', 'See its logs'));

    // Back up; then three restarts inside the window is a crash loop, said once.
    $set([$svc('docker:aaa', 'shop', 'running'), $svc('docker:ddd', 'worker', 'running')], ['docker:aaa' => $stat(), 'docker:ddd' => $stat(0)]);
    expect($watch())->toBe(1);
    Notification::assertSentTo($workspace->owner, MonitorAlert::class, fn (MonitorAlert $n) => $n->subject === 'shop is running again on web-1');

    $set([$svc('docker:aaa', 'shop', 'running'), $svc('docker:ddd', 'worker', 'running')], ['docker:aaa' => $stat(), 'docker:ddd' => $stat(4)]);
    expect($watch())->toBe(1)->and($watch())->toBe(0);
    Notification::assertSentTo($workspace->owner, MonitorAlert::class, fn (MonitorAlert $n) => $n->subject === 'worker keeps restarting on web-1');

    expect(Activity::query()->pluck('action')->all())->toBe(['service.down', 'service.up', 'service.crash_loop']);
});
