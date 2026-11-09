<?php

use App\Enums\Plan;
use App\Mcp\Scopes;
use App\Mcp\Tools\MonitorTools;
use App\Models\Activity;
use App\Models\Machine;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Monitors\HostResolver;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('mcp.groups', [MonitorTools::class]);
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

/** @return array{0: Workspace, 1: string} */
function monitorToolsSetup(?Plan $plan = Plan::Pro): array
{
    $factory = Workspace::factory();
    $workspace = ($plan ? $factory->onPlan($plan) : $factory->trialExpired())->create(['name' => 'Acme']);

    return [$workspace, mcpKey($workspace)];
}

function monitorToolsMonitor(Workspace $workspace, array $overrides = []): Monitor
{
    return $workspace->monitors()->create([
        'name' => 'Shop', 'type' => 'http', 'url' => 'https://shop.example.com/',
        'interval_seconds' => 60, 'timeout_seconds' => 10, 'status' => 'pending', 'alerts' => true,
        ...$overrides,
    ]);
}

function monitorToolsMachine(Workspace $workspace, string $name = 'web-1'): Machine
{
    $machine = Machine::factory()->for($workspace)->create(['name' => $name]);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()])->save();

    return $machine;
}

function monitorToolsMember(Workspace $workspace, string $role = 'viewer'): User
{
    $user = User::factory()->create();
    $workspace->members()->attach($user, ['role' => $role]);

    return $user;
}

/** @return list<string> */
function monitorToolsActions(Workspace $workspace): array
{
    return Activity::query()->where('workspace_id', $workspace->id)->orderBy('id')->pluck('action')->all();
}

test('tools are listed by what the connection and role allow', function () {
    [$workspace, $key] = monitorToolsSetup();

    $all = collect(mcpRequest($workspace, $key, 'tools/list')->json('result.tools'))->keyBy('name');
    expect($all->keys()->all())->toEqualCanonicalizing([
        'list_monitors', 'get_monitor', 'create_monitor', 'update_monitor', 'check_monitor_now', 'pause_monitor', 'resume_monitor',
        'mute_monitor', 'unmute_monitor', 'delete_monitor', 'get_alert_settings', 'update_alert_settings', 'send_test_alert',
    ]);
    expect($all['delete_monitor']['annotations']['destructiveHint'])->toBeTrue()
        ->and($all['delete_monitor']['inputSchema']['required'])->toBe(['monitor', 'confirm'])
        ->and($all['list_monitors']['annotations']['readOnlyHint'])->toBeTrue()
        ->and($all['create_monitor']['annotations']['readOnlyHint'])->toBeFalse()
        ->and($all['check_monitor_now']['annotations']['openWorldHint'])->toBeTrue()
        ->and($all['send_test_alert']['annotations']['openWorldHint'])->toBeTrue()
        ->and($all['pause_monitor']['annotations']['idempotentHint'])->toBeTrue()
        ->and($all['mute_monitor']['inputSchema']['properties']['minutes']['enum'])->toBe([30, 60, 120, 240, 480, 1440]);

    $read = collect(mcpRequest($workspace, mcpKey($workspace, scopes: [Scopes::READ]), 'tools/list')->json('result.tools'))->pluck('name');
    expect($read->all())->toEqualCanonicalizing(['list_monitors', 'get_monitor', 'get_alert_settings']);

    $viewer = collect(mcpRequest($workspace, mcpKey($workspace, monitorToolsMember($workspace)), 'tools/list')->json('result.tools'))->pluck('name');
    expect($viewer->all())->toEqualCanonicalizing(['list_monitors', 'get_monitor', 'get_alert_settings']);
});

test('list_monitors shows status, uptime, alerts and the machine, and filters and pages', function () {
    [$workspace, $key] = monitorToolsSetup();
    $machine = monitorToolsMachine($workspace);
    $shop = monitorToolsMonitor($workspace, ['status' => 'up', 'machine_id' => $machine->id, 'last_response_ms' => 120, 'last_checked_at' => now(), 'status_changed_at' => now()->subHour()]);
    $shop->recordDay(true, 120);
    $shop->recordDay(false, null);
    $shop->save();
    monitorToolsMonitor($workspace, ['name' => 'API', 'url' => 'https://api.example.com/health', 'status' => 'down', 'last_error' => 'Returned 503.', 'muted_until' => now()->addHour()]);
    monitorToolsMonitor($workspace, ['name' => 'Postgres', 'type' => 'tcp', 'url' => null, 'host' => 'db.example.com', 'port' => 5432, 'paused' => true]);
    monitorToolsMonitor(Workspace::factory()->create(), ['name' => 'Theirs']);

    $all = mcpCall($workspace, $key, 'list_monitors');
    expect($all['error'])->toBeFalse()
        ->and($all['data']['total'])->toBe(3)
        ->and(collect($all['data']['monitors'])->pluck('name')->all())->toBe(['API', 'Postgres', 'Shop'])
        ->and($all['data']['plan'])->toBe(['monitors' => 3, 'monitorLimit' => 50, 'minIntervalSeconds' => 60]);

    $row = collect($all['data']['monitors'])->firstWhere('name', 'Shop');
    expect($row)->toMatchArray([
        'id' => $shop->id, 'type' => 'http', 'target' => 'https://shop.example.com/', 'status' => 'up', 'responseMs' => 120,
        'intervalSeconds' => 60, 'paused' => false,
        'machine' => ['id' => $machine->id, 'name' => 'web-1', 'url' => 'https://'.$workspace->host().'/machines/'.$machine->id],
        'url' => 'https://'.$workspace->host().'/monitors/'.$shop->id,
    ])->and($row['uptime'])->toBe(['last7Days' => 50, 'last30Days' => 50])
        ->and($row['alerts'])->toMatchArray(['enabled' => true, 'muted' => false, 'mutedUntil' => null]);

    $postgres = collect($all['data']['monitors'])->firstWhere('name', 'Postgres');
    expect($postgres)->toMatchArray(['type' => 'tcp', 'target' => 'db.example.com:5432', 'status' => 'paused', 'machine' => null]);
    expect(collect($all['data']['monitors'])->firstWhere('name', 'API')['alerts']['muted'])->toBeTrue();

    $names = fn (array $args) => collect(mcpCall($workspace, $key, 'list_monitors', $args)['data']['monitors'])->pluck('name')->all();
    expect($names(['status' => 'down']))->toBe(['API'])
        ->and($names(['status' => 'paused']))->toBe(['Postgres'])
        ->and($names(['status' => 'up']))->toBe(['Shop'])
        ->and($names(['type' => 'tcp']))->toBe(['Postgres'])
        ->and($names(['machine' => 'web-1']))->toBe(['Shop'])
        ->and($names(['query' => 'api.example']))->toBe(['API'])
        ->and($names(['query' => 'DB.EXAMPLE']))->toBe(['Postgres'])
        ->and($names(['muted' => true]))->toBe(['API'])
        ->and($names(['muted' => false]))->toBe(['Postgres', 'Shop']);

    // Muted because its machine is in maintenance.
    $machine->forceFill(['maintenance_until' => now()->addMinutes(30)])->save();
    expect($names(['muted' => true]))->toBe(['API', 'Shop']);
    $shopRow = collect(mcpCall($workspace, $key, 'list_monitors', ['query' => 'shop'])['data']['monitors'])->first();
    expect($shopRow['alerts']['muted'])->toBeTrue()
        ->and($shopRow['alerts']['mutedUntil'])->toBeNull()
        ->and($shopRow['alerts']['machineMaintenanceUntil'])->not->toBeNull();

    $page = mcpCall($workspace, $key, 'list_monitors', ['limit' => 2]);
    expect($page['data'])->toMatchArray(['total' => 3, 'count' => 2, 'more' => true]);
    expect(mcpCall($workspace, $key, 'list_monitors', ['limit' => 2, 'offset' => 2])['data'])->toMatchArray(['count' => 1, 'more' => false]);

    expect(mcpCall($workspace, $key, 'list_monitors', ['status' => 'sleeping'])['text'])->toContain('Invalid arguments')
        ->and(mcpCall($workspace, $key, 'list_monitors', ['limit' => 1000])['error'])->toBeTrue()
        ->and(mcpCall($workspace, $key, 'list_monitors', ['machine' => 'nope'])['text'])->toContain('No machine “nope”');
});

test('get_monitor shows history, incidents and alert state like the monitor page', function () {
    [$workspace, $key] = monitorToolsSetup();
    $workspace->forceFill(['alert_email' => false, 'alert_webhook_url' => 'https://hooks.slack.com/services/T/B/secret'])->save();
    $monitor = monitorToolsMonitor($workspace, ['status' => 'down', 'last_error' => 'Timed out after 10 s.', 'expected_status' => 204, 'keyword' => 'cart', 'ssl_expires_at' => now()->addDays(20)]);
    foreach ([[true, 100, 50], [true, 300, 40], [false, null, 30], [false, null, 20]] as [$ok, $ms, $ago]) {
        MonitorCheck::query()->create(['monitor_id' => $monitor->id, 'checked_at' => now()->subMinutes($ago), 'ok' => $ok, 'response_ms' => $ms, 'status_code' => $ok ? 204 : null, 'error' => $ok ? null : 'Timed out after 10 s.']);
        $monitor->recordDay($ok, $ms);
    }
    $monitor->save();
    $monitor->incidents()->create(['started_at' => now()->subDays(3), 'resolved_at' => now()->subDays(3)->addMinutes(5), 'cause' => 'Returned 500.']);
    $open = $monitor->incidents()->create(['started_at' => now()->subMinutes(20), 'cause' => 'Timed out after 10 s.']);

    $result = mcpCall($workspace, $key, 'get_monitor', ['monitor' => 'shop']);
    expect($result['error'])->toBeFalse();
    $data = $result['data'];
    expect($data['monitor'])->toMatchArray(['id' => $monitor->id, 'status' => 'down', 'error' => 'Timed out after 10 s.'])
        ->and($data['monitor']['settings'])->toMatchArray(['type' => 'http', 'url' => 'https://shop.example.com/', 'intervalSeconds' => 60, 'timeoutSeconds' => 10, 'expectedStatus' => 204, 'keyword' => 'cart', 'alerts' => true])
        ->and($data['uptime'])->toBe(['last24Hours' => 50, 'last7Days' => 50, 'last30Days' => 50, 'last90Days' => 50])
        ->and($data['days'])->toHaveCount(30)
        ->and(end($data['days']))->toBe(['day' => now()->toDateString(), 'percent' => 50])
        ->and($data['responseTime'])->toMatchArray(['range' => '24h', 'checks' => 4, 'failed' => 2, 'averageMs' => 200, 'fastestMs' => 100, 'slowestMs' => 300])
        ->and($data['responseTime']['series'])->toHaveCount(24)
        ->and($data['checks'])->toHaveCount(4)
        ->and($data['checks'][0])->toMatchArray(['ok' => false, 'error' => 'Timed out after 10 s.'])
        ->and($data['incidents'])->toHaveCount(2)
        ->and($data['incidents'][0])->toMatchArray(['id' => $open->id, 'ongoing' => true, 'cause' => 'Timed out after 10 s.'])
        ->and($data['incidents'][1]['seconds'])->toBe(300)
        ->and($data['openIncident']['id'])->toBe($open->id)
        ->and($data['certificate']['daysLeft'])->toBeIn([19, 20])
        ->and($data['alertState'])->toMatchArray(['enabled' => true, 'muted' => false, 'channels' => ['email' => false, 'webhook' => true], 'wouldAlert' => true]);

    // The webhook's address is never in it.
    expect($result['text'])->not->toContain('secret');

    $limited = mcpCall($workspace, $key, 'get_monitor', ['monitor' => (string) $monitor->id, 'checks' => 1, 'incidents' => 0, 'days' => 90, 'range' => '7d'])['data'];
    expect($limited['checks'])->toHaveCount(1)
        ->and($limited['incidents'])->toBe([])
        ->and($limited['days'])->toHaveCount(90)
        ->and($limited['responseTime']['range'])->toBe('7d');

    $monitor->forceFill(['muted_until' => now()->addHour()])->save();
    expect(mcpCall($workspace, $key, 'get_monitor', ['monitor' => $monitor->id])['data']['alertState'])->toMatchArray(['muted' => true, 'wouldAlert' => false]);

    // Two with one name: say which.
    monitorToolsMonitor($workspace, ['url' => 'https://shop2.example.com/']);
    expect(mcpCall($workspace, $key, 'get_monitor', ['monitor' => 'Shop'])['text'])->toContain('Several monitors are called “Shop”');

    // Another workspace's monitor isn't there.
    $theirs = monitorToolsMonitor(Workspace::factory()->create(), ['name' => 'Theirs']);
    expect(mcpCall($workspace, $key, 'get_monitor', ['monitor' => $theirs->id])['text'])->toContain('No monitor “'.$theirs->id.'” in Acme')
        ->and(mcpCall($workspace, $key, 'get_monitor', ['monitor' => 'Theirs'])['error'])->toBeTrue()
        ->and(mcpCall($workspace, $key, 'get_monitor', ['monitor' => 'sho'])['text'])->toContain('Did you mean: Shop');
});

test('create_monitor adds a monitor as the dialog does, and checks it straight away', function () {
    [$workspace, $key] = monitorToolsSetup();
    $machine = monitorToolsMachine($workspace);
    Http::fake(['*' => Http::response('ok')]);

    $result = mcpCall($workspace, $key, 'create_monitor', ['url' => 'https://www.shop.example.com/', 'machine' => 'web-1']);
    expect($result['error'])->toBeFalse()
        ->and($result['data']['monitor'])->toMatchArray(['name' => 'shop.example.com', 'type' => 'http', 'target' => 'https://www.shop.example.com/', 'intervalSeconds' => 60])
        ->and($result['data']['monitor']['settings'])->toMatchArray(['timeoutSeconds' => 10, 'alerts' => true, 'machineId' => $machine->id]);

    $monitor = Monitor::query()->sole();
    expect($monitor->workspace_id)->toBe($workspace->id)
        // The first check ran once the answer went out.
        ->and($monitor->status)->toBe('up')
        ->and($monitor->checks()->count())->toBe(1);

    $activity = Activity::query()->where('action', 'monitor.add')->sole();
    expect($activity->subject)->toBe('shop.example.com')
        ->and($activity->user_id)->toBe($workspace->owner_id)
        ->and($activity->machine_id)->toBe($machine->id)
        ->and($activity->meta)->toMatchArray(['target' => 'https://www.shop.example.com/', 'via' => 'mcp']);

    // The dashboard's checks, word for word.
    expect(mcpCall($workspace, $key, 'create_monitor', ['url' => 'http://10.0.0.5/'])['text'])->toContain('Private and internal addresses can’t be monitored from the panel.')
        ->and(mcpCall($workspace, $key, 'create_monitor', ['url' => 'https://nowhere.example/'])['text'])->toContain('Couldn’t find nowhere.example in DNS.')
        ->and(mcpCall($workspace, $key, 'create_monitor', ['type' => 'tcp', 'host' => '127.0.0.1', 'port' => 5432])['text'])->toContain('Private and internal addresses')
        ->and(mcpCall($workspace, $key, 'create_monitor', ['url' => 'ftp://shop.example.com'])['text'])->toContain('Enter a full address, starting with http:// or https://.')
        ->and(mcpCall($workspace, $key, 'create_monitor', ['type' => 'tcp', 'host' => 'db.example.com'])['text'])->toContain('port field is required')
        ->and(mcpCall($workspace, $key, 'create_monitor', ['url' => 'https://a.example.com', 'interval' => 45])['text'])->toContain('interval must be one of 30, 60, 180, 300, 600, 1800 seconds.')
        ->and(mcpCall($workspace, $key, 'create_monitor', ['url' => 'https://a.example.com', 'timeout' => 60])['text'])->toContain('timeout')
        ->and(mcpCall($workspace, $key, 'create_monitor', ['url' => 'https://a.example.com', 'machine' => 'nope'])['text'])->toContain('No machine “nope”')
        ->and(mcpCall($workspace, $key, 'create_monitor', [])['text'])->toContain('Invalid arguments');

    // Someone else's machine can't be linked.
    $theirs = monitorToolsMachine(Workspace::factory()->create(), 'theirs');
    expect(mcpCall($workspace, $key, 'create_monitor', ['url' => 'https://b.example.com', 'machine' => (string) $theirs->id])['error'])->toBeTrue()
        ->and(Monitor::query()->count())->toBe(1);

    // A port: the type follows host and port. (A local one, allowed here
    // so the first check connects without leaving the machine.)
    config()->set('monitors.allow_private', true);
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
    $tcp = mcpCall($workspace, $key, 'create_monitor', ['name' => 'Postgres', 'host' => '127.0.0.1', 'port' => $port, 'interval' => 300, 'timeout' => 5, 'alerts' => false]);
    fclose($server);
    expect($tcp['error'])->toBeFalse()
        ->and($tcp['data']['monitor'])->toMatchArray(['type' => 'tcp', 'target' => "127.0.0.1:{$port}", 'intervalSeconds' => 300])
        ->and($tcp['data']['monitor']['settings'])->toMatchArray(['url' => null, 'timeoutSeconds' => 5, 'alerts' => false])
        ->and(Monitor::query()->where('type', 'tcp')->sole()->status)->toBe('up');

    expect(Monitor::query()->count())->toBe(2);
});

test('the plan sets how many monitors and how often they check', function () {
    [$workspace, $key] = monitorToolsSetup(Plan::Essential);

    expect(mcpCall($workspace, $key, 'create_monitor', ['url' => 'https://shop.example.com', 'interval' => 60])['text'])->toContain('Your plan checks at most every 3 minutes.');

    // Left out, the interval is the plan's shortest.
    Http::fake(['*' => Http::response('ok')]);
    expect(mcpCall($workspace, $key, 'create_monitor', ['url' => 'https://shop.example.com'])['data']['monitor']['intervalSeconds'])->toBe(180);

    foreach (range(2, 10) as $i) {
        monitorToolsMonitor($workspace, ['name' => "M{$i}", 'interval_seconds' => 180]);
    }
    expect(mcpCall($workspace, $key, 'create_monitor', ['url' => 'https://more.example.com'])['text'])->toContain('Your plan includes 10 monitors. Remove one or upgrade to add more.');

    [$none, $noneKey] = monitorToolsSetup(null);
    // With no plan, AI apps get no tool calls at all.
    expect(mcpCall($none, $noneKey, 'create_monitor', ['url' => 'https://shop.example.com'])['text'])->toContain('has no plan, so AI apps can');
});

test('changes need the right role and a connection that may make them', function () {
    [$workspace] = monitorToolsSetup();
    $monitor = monitorToolsMonitor($workspace);
    $viewer = mcpKey($workspace, monitorToolsMember($workspace));
    $readOnly = mcpKey($workspace, scopes: [Scopes::READ]);

    foreach ([
        ['create_monitor', ['url' => 'https://a.example.com']],
        ['update_monitor', ['monitor' => $monitor->id, 'name' => 'X']],
        ['pause_monitor', ['monitor' => $monitor->id]],
        ['mute_monitor', ['monitor' => $monitor->id]],
        ['delete_monitor', ['monitor' => $monitor->id, 'confirm' => 'Shop']],
        ['update_alert_settings', ['email' => false]],
        ['send_test_alert', ['webhook' => 'https://hooks.slack.com/x']],
        ['check_monitor_now', ['monitor' => $monitor->id]],
    ] as [$tool, $args]) {
        expect(mcpCall($workspace, $viewer, $tool, $args))->toMatchArray(['error' => true])
            ->and(mcpCall($workspace, $viewer, $tool, $args)['text'])->toContain('doesn’t allow that')
            ->and(mcpCall($workspace, $readOnly, $tool, $args)['text'])->toContain('read-only');
    }

    // A member can manage machines, so monitors too.
    $member = mcpKey($workspace, monitorToolsMember($workspace, 'member'));
    expect(mcpCall($workspace, $member, 'pause_monitor', ['monitor' => $monitor->id])['error'])->toBeFalse();

    expect($monitor->fresh()->name)->toBe('Shop')
        ->and(Monitor::query()->count())->toBe(1);
});

test('update_monitor changes only what is given, under the same rules', function () {
    [$workspace, $key] = monitorToolsSetup();
    $machine = monitorToolsMachine($workspace);
    $monitor = monitorToolsMonitor($workspace, ['keyword' => 'cart', 'expected_status' => 200, 'machine_id' => $machine->id, 'failures' => 1, 'ssl_expires_at' => now()->addDays(40)]);

    $renamed = mcpCall($workspace, $key, 'update_monitor', ['monitor' => 'Shop', 'name' => 'Storefront', 'interval' => 300]);
    expect($renamed['error'])->toBeFalse()
        ->and($renamed['data']['changed'])->toEqualCanonicalizing(['name', 'intervalSeconds'])
        ->and($renamed['data']['monitor']['settings'])->toMatchArray(['url' => 'https://shop.example.com/', 'keyword' => 'cart', 'expectedStatus' => 200, 'machineId' => $machine->id, 'intervalSeconds' => 300]);
    expect($monitor->fresh()->failures)->toBe(1)
        ->and(monitorToolsActions($workspace))->toBe(['monitor.update'])
        ->and(Activity::query()->latest('id')->first()->subject)->toBe('Storefront');

    // Clearing the optional checks and unlinking the machine.
    $cleared = mcpCall($workspace, $key, 'update_monitor', ['monitor' => $monitor->id, 'expected_status' => 0, 'keyword' => '', 'machine' => '']);
    expect($cleared['data']['monitor']['settings'])->toMatchArray(['expectedStatus' => null, 'keyword' => null, 'machineId' => null])
        ->and($cleared['data']['monitor']['machine'])->toBeNull();

    // A new target starts its record fresh.
    $moved = mcpCall($workspace, $key, 'update_monitor', ['monitor' => $monitor->id, 'host' => 'db.example.com', 'port' => 5432]);
    expect($moved['data']['monitor'])->toMatchArray(['type' => 'tcp', 'target' => 'db.example.com:5432'])
        ->and($moved['data']['note'])->toContain('now checks db.example.com:5432');
    expect($monitor->fresh())->failures->toBe(0)->ssl_expires_at->toBeNull()->url->toBeNull();

    // Same values again: nothing recorded.
    $count = count(monitorToolsActions($workspace));
    expect(mcpCall($workspace, $key, 'update_monitor', ['monitor' => $monitor->id, 'port' => 5432])['data']['changed'])->toBe([])
        ->and(monitorToolsActions($workspace))->toHaveCount($count);

    expect(mcpCall($workspace, $key, 'update_monitor', ['monitor' => $monitor->id])['text'])->toContain('Nothing to change')
        ->and(mcpCall($workspace, $key, 'update_monitor', ['monitor' => $monitor->id, 'type' => 'http'])['text'])->toContain('url field is required')
        ->and(mcpCall($workspace, $key, 'update_monitor', ['monitor' => $monitor->id, 'host' => '192.168.1.10'])['text'])->toContain('Private and internal addresses can’t be monitored from the panel.')
        ->and(mcpCall($workspace, $key, 'update_monitor', ['monitor' => $monitor->id, 'interval' => 30])['text'])->toContain('Your plan checks at most every 1 minutes.')
        ->and(mcpCall($workspace, $key, 'update_monitor', ['monitor' => $monitor->id, 'name' => str_repeat('x', 81)])['error'])->toBeTrue();

    $theirs = monitorToolsMonitor(Workspace::factory()->create());
    expect(mcpCall($workspace, $key, 'update_monitor', ['monitor' => $theirs->id, 'name' => 'Mine now'])['error'])->toBeTrue()
        ->and($theirs->fresh()->name)->toBe('Shop');
});

test('delete_monitor needs the name typed to confirm', function () {
    [$workspace, $key] = monitorToolsSetup();
    $monitor = monitorToolsMonitor($workspace);
    MonitorCheck::query()->create(['monitor_id' => $monitor->id, 'checked_at' => now(), 'ok' => true]);

    expect(mcpCall($workspace, $key, 'delete_monitor', ['monitor' => $monitor->id])['text'])->toContain('confirm set to the monitor’s name exactly: “Shop”')
        ->and(mcpCall($workspace, $key, 'delete_monitor', ['monitor' => $monitor->id, 'confirm' => 'shop'])['text'])->toContain('confirm doesn’t match');
    expect(Monitor::query()->count())->toBe(1);

    $result = mcpCall($workspace, $key, 'delete_monitor', ['monitor' => $monitor->id, 'confirm' => 'Shop']);
    expect($result['error'])->toBeFalse()
        ->and($result['data']['removed'])->toBe(['id' => $monitor->id, 'name' => 'Shop', 'target' => 'https://shop.example.com/'])
        ->and(Monitor::query()->count())->toBe(0)
        ->and(MonitorCheck::query()->count())->toBe(0)
        ->and(monitorToolsActions($workspace))->toBe(['monitor.remove']);

    $theirs = monitorToolsMonitor(Workspace::factory()->create());
    expect(mcpCall($workspace, $key, 'delete_monitor', ['monitor' => $theirs->id, 'confirm' => 'Shop'])['error'])->toBeTrue()
        ->and($theirs->fresh())->not->toBeNull();
});

test('check_monitor_now checks it and says what it found', function () {
    [$workspace, $key] = monitorToolsSetup();
    $monitor = monitorToolsMonitor($workspace);
    $up = false;
    Http::fake(function () use (&$up) {
        return $up ? Http::response('fine') : Http::response('nope', 503);
    });

    $failed = mcpCall($workspace, $key, 'check_monitor_now', ['monitor' => 'Shop']);
    expect($failed['error'])->toBeFalse()
        ->and($failed['data']['check'])->toMatchArray(['ok' => false, 'statusCode' => 503, 'error' => 'Returned 503.'])
        ->and($failed['data']['monitor']['status'])->toBe('pending')
        ->and($failed['data']['note'])->toContain('1 more failed check in a row and it counts as down');

    expect(mcpCall($workspace, $key, 'check_monitor_now', ['monitor' => 'Shop'])['data']['note'])->toBe('Shop is down: Returned 503.')
        ->and($monitor->fresh()->status)->toBe('down')
        ->and($monitor->openIncident()->exists())->toBeTrue();

    $up = true;
    $back = mcpCall($workspace, $key, 'check_monitor_now', ['monitor' => $monitor->id]);
    expect($back['data']['check']['ok'])->toBeTrue()
        ->and($back['data']['monitor']['status'])->toBe('up')
        ->and($back['data']['note'])->toBe('Shop is up.')
        ->and(MonitorCheck::query()->count())->toBe(3);

    $monitor->forceFill(['paused' => true])->save();
    expect(mcpCall($workspace, $key, 'check_monitor_now', ['monitor' => $monitor->id])['text'])->toContain('Shop is paused');

    $theirs = monitorToolsMonitor(Workspace::factory()->create());
    expect(mcpCall($workspace, $key, 'check_monitor_now', ['monitor' => $theirs->id])['error'])->toBeTrue()
        ->and($theirs->checks()->count())->toBe(0);
});

test('pausing closes an open incident and resuming checks it again', function () {
    [$workspace, $key] = monitorToolsSetup();
    $monitor = monitorToolsMonitor($workspace, ['status' => 'down', 'failures' => 3]);
    $monitor->incidents()->create(['started_at' => now()->subMinutes(5), 'cause' => 'Timed out']);

    $paused = mcpCall($workspace, $key, 'pause_monitor', ['monitor' => 'Shop']);
    expect($paused['data']['monitor']['status'])->toBe('paused');
    expect($monitor->fresh())->paused->toBeTrue()->status->toBe('pending')->failures->toBe(0)
        ->and($monitor->fresh()->openIncident)->toBeNull();

    // Again: nothing more to do.
    expect(mcpCall($workspace, $key, 'pause_monitor', ['monitor' => 'Shop'])['data']['note'])->toBe('Shop was already paused.');

    $resumed = mcpCall($workspace, $key, 'resume_monitor', ['monitor' => 'Shop']);
    expect($resumed['data']['monitor']['status'])->toBe('pending')
        ->and($monitor->fresh()->paused)->toBeFalse()
        ->and($monitor->fresh()->next_check_at->lte(now()))->toBeTrue();
    expect(mcpCall($workspace, $key, 'resume_monitor', ['monitor' => 'Shop'])['data']['note'])->toBe('Shop isn’t paused.');

    expect(monitorToolsActions($workspace))->toBe(['monitor.pause', 'monitor.resume']);
});

test('mute_monitor holds alerts for a while and unmute_monitor ends it', function () {
    [$workspace, $key] = monitorToolsSetup();
    $machine = monitorToolsMachine($workspace);
    $monitor = monitorToolsMonitor($workspace, ['machine_id' => $machine->id]);

    $muted = mcpCall($workspace, $key, 'mute_monitor', ['monitor' => 'Shop', 'minutes' => 240]);
    expect($muted['error'])->toBeFalse()
        ->and($muted['data']['monitor']['alerts']['muted'])->toBeTrue()
        ->and($monitor->fresh()->muted())->toBeTrue()
        ->and($monitor->fresh()->muted_until->diffInMinutes(now()->addMinutes(240), true))->toBeLessThan(1);
    $activity = Activity::query()->where('action', 'monitor.mute')->sole();
    expect($activity->meta)->toMatchArray(['minutes' => 240, 'via' => 'mcp'])
        ->and($activity->machine_id)->toBe($machine->id);

    // An hour unless said.
    mcpCall($workspace, $key, 'mute_monitor', ['monitor' => 'Shop']);
    expect($monitor->fresh()->muted_until->diffInMinutes(now()->addHour(), true))->toBeLessThan(1);
    expect(mcpCall($workspace, $key, 'mute_monitor', ['monitor' => 'Shop', 'minutes' => 7])['text'])->toContain('minutes must be one of 30, 60, 120, 240, 480, 1440.');

    $unmuted = mcpCall($workspace, $key, 'unmute_monitor', ['monitor' => 'Shop']);
    expect($unmuted['data']['note'])->toBe('Alerts for Shop are on again.')
        ->and($monitor->fresh()->muted_until)->toBeNull();
    expect(mcpCall($workspace, $key, 'unmute_monitor', ['monitor' => 'Shop'])['data']['note'])->toBe('Shop wasn’t muted.');

    // Its machine's maintenance still holds them, and it says so.
    $machine->forceFill(['maintenance_until' => now()->addHour()])->save();
    expect(mcpCall($workspace, $key, 'unmute_monitor', ['monitor' => 'Shop'])['data']['note'])->toContain('Its machine web-1 is in maintenance until');

    expect(monitorToolsActions($workspace))->toBe(['monitor.mute', 'monitor.mute', 'monitor.unmute']);
});

test('alert settings never give the webhook back, and change as the dialog does', function () {
    [$workspace, $key] = monitorToolsSetup();
    $workspace->forceFill(['alert_webhook_url' => 'https://hooks.slack.com/services/T/B/secret'])->save();
    $admin = monitorToolsMember($workspace, 'admin');
    $workspace->members()->updateExistingPivot($admin->id, ['alert_emails' => 'problems']);
    monitorToolsMonitor($workspace, ['alerts' => false]);
    monitorToolsMonitor($workspace, ['name' => 'API', 'muted_until' => now()->addHour()]);

    $owner = mcpCall($workspace, $key, 'get_alert_settings');
    expect($owner['data'])->toMatchArray([
        'webhook' => ['set' => true, 'host' => 'hooks.slack.com'],
        'monitors' => ['total' => 2, 'alertsOff' => 1, 'muted' => 1],
        'canChange' => true,
    ])->and($owner['data']['email']['on'])->toBeTrue()
        ->and(collect($owner['data']['email']['recipients'])->pluck('gets', 'role')->all())->toEqual(['admin' => 'problems', 'owner' => 'all'])
        ->and($owner['text'])->not->toContain('secret');

    $viewer = mcpCall($workspace, mcpKey($workspace, monitorToolsMember($workspace)), 'get_alert_settings');
    expect($viewer['data']['webhook'])->toBe(['set' => true, 'host' => null])
        ->and($viewer['data']['email']['recipients'])->toBeNull()
        ->and($viewer['data']['canChange'])->toBeFalse();

    // Only email: the webhook stays.
    $saved = mcpCall($workspace, $key, 'update_alert_settings', ['email' => false]);
    expect($saved['error'])->toBeFalse()
        ->and($saved['data']['email']['on'])->toBeFalse()
        ->and($workspace->fresh()->alert_webhook_url)->toBe('https://hooks.slack.com/services/T/B/secret');

    expect(mcpCall($workspace, $key, 'update_alert_settings', ['webhook' => 'http://hooks.slack.com/x'])['text'])->toContain('https://')
        ->and(mcpCall($workspace, $key, 'update_alert_settings', ['webhook' => 'https://internal.example/hook'])['text'])->toContain('That address can’t be reached from the panel.')
        ->and(mcpCall($workspace, $key, 'update_alert_settings', [])['text'])->toContain('Nothing to change')
        ->and($workspace->fresh()->alert_webhook_url)->toBe('https://hooks.slack.com/services/T/B/secret');

    $changed = mcpCall($workspace, $key, 'update_alert_settings', ['webhook' => 'https://discord.com/api/webhooks/1/token']);
    expect($changed['data']['webhook'])->toBe(['set' => true, 'host' => 'discord.com'])
        ->and($changed['text'])->not->toContain('token')
        ->and($workspace->fresh())->alert_webhook_url->toBe('https://discord.com/api/webhooks/1/token')->alert_email->toBeFalse();

    mcpCall($workspace, $key, 'update_alert_settings', ['webhook' => '', 'email' => true]);
    expect($workspace->fresh())->alert_webhook_url->toBeNull()->alert_email->toBeTrue();

    $metas = Activity::query()->where('action', 'alerts.update')->orderBy('id')->get()->map(fn ($a) => [$a->meta['email'], $a->meta['webhook'], $a->meta['via']])->all();
    expect($metas)->toBe([[false, true, 'mcp'], [false, true, 'mcp'], [true, false, 'mcp']]);
});

test('send_test_alert posts to the webhook given or the saved one', function () {
    [$workspace, $key] = monitorToolsSetup();
    Http::fake(fn (HttpRequest $request) => str_contains($request->url(), 'broken') ? Http::response('no', 404) : Http::response('ok'));

    expect(mcpCall($workspace, $key, 'send_test_alert')['text'])->toContain('No webhook is set');

    $given = mcpCall($workspace, $key, 'send_test_alert', ['webhook' => 'https://hooks.slack.com/services/T/B/x']);
    expect($given['data'])->toMatchArray(['ok' => true, 'error' => null, 'webhook' => 'given', 'host' => 'hooks.slack.com']);
    Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://hooks.slack.com/services/T/B/x' && $r['event'] === 'test' && str_contains($r['text'], 'alerts for Acme will arrive here'));

    $workspace->forceFill(['alert_webhook_url' => 'https://hooks.example.com/broken'])->save();
    $saved = mcpCall($workspace, $key, 'send_test_alert');
    expect($saved['data'])->toMatchArray(['ok' => false, 'error' => 'The webhook answered 404.', 'webhook' => 'saved'])
        ->and($saved['text'])->not->toContain('/broken');

    expect(mcpCall($workspace, $key, 'send_test_alert', ['webhook' => 'http://hooks.slack.com/x'])['text'])->toContain('https://')
        ->and(mcpCall($workspace, $key, 'send_test_alert', ['webhook' => 'https://internal.example/x'])['data']['error'])->toBe('That address can’t be reached from the panel.');

    // As often as the dashboard allows: ten a minute.
    foreach (range(1, 7) as $i) {
        mcpCall($workspace, $key, 'send_test_alert', ['webhook' => 'https://hooks.slack.com/services/T/B/x']);
    }
    expect(mcpCall($workspace, $key, 'send_test_alert', ['webhook' => 'https://hooks.slack.com/services/T/B/x'])['text'])->toContain('That’s a lot of test alerts in a minute.');
});
