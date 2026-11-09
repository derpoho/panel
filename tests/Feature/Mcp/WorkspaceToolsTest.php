<?php

use App\Enums\Plan;
use App\Enums\WorkspaceRole;
use App\Mcp\McpContext;
use App\Mcp\Scopes;
use App\Mcp\Tools\Workspace\Issues;
use App\Mcp\Tools\WorkspaceTools;
use App\Models\Activity;
use App\Models\Machine;
use App\Models\McpToken;
use App\Models\McpToolCall;
use App\Models\Monitor;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Health\WorkspaceHealth;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => config()->set('mcp.groups', [WorkspaceTools::class]));

/**
 * A workspace with a connected production machine running umami (stopped
 * unexpectedly) and nginx (crash-looping), a monitor on it that's down,
 * and a workflow.
 *
 * @return array{0: Workspace, 1: Machine, 2: Monitor}
 */
function wsToolsWorkspace(): array
{
    $workspace = Workspace::factory()->create(['name' => 'Acme']);
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'ip_address' => '203.0.113.7', 'environment' => 'production', 'provider' => 'Hetzner']);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'backup_schedule' => 'daily',
        'last_backup_at' => now(),
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
            ['key' => 'docker:abc123def456', 'name' => 'serveros-umami-umami-1', 'kind' => 'web', 'manager' => 'compose', 'status' => 'exited', 'ports' => [3000], 'details' => ['managed' => 'true']],
            ['key' => 'nginx.service', 'name' => 'nginx.service', 'kind' => 'proxy', 'manager' => 'systemd', 'status' => 'activating', 'ports' => [80, 443]],
        ]],
        'alert_state' => ['services' => [
            'down' => ['docker:abc123def456' => now()->subMinutes(5)->getTimestamp()],
            'looping' => ['nginx.service' => true],
        ]],
    ])->save();

    $monitor = $workspace->monitors()->create([
        'name' => 'Shop', 'type' => 'http', 'url' => 'https://shop.example.com/', 'machine_id' => $machine->id,
        'status' => 'down', 'last_error' => 'Returned 502.', 'interval_seconds' => 60, 'timeout_seconds' => 10,
        'status_changed_at' => now()->subMinutes(2),
    ]);
    $workspace->workflows()->create(['name' => 'Redeploy umami', 'enabled' => true, 'graph' => ['nodes' => [], 'edges' => []]]);

    return [$workspace, $machine, $monitor];
}

function wsToolsMember(Workspace $workspace, WorkspaceRole $role = WorkspaceRole::Viewer, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $workspace->members()->attach($user, ['role' => $role->value]);

    return $user;
}

/** The Home's Needs attention list, as the dashboard has it. */
function wsToolsHomeIssues(Workspace $workspace, ?User $user = null): array
{
    return test()->actingAs($user ?? $workspace->owner)->get('https://'.$workspace->host().'/')->viewData('page')['props']['issues'];
}

test('the group offers its tools, the write one only to connections and roles that may change things', function () {
    [$workspace] = wsToolsWorkspace();
    $names = fn (string $key) => collect(mcpRequest($workspace, $key, 'tools/list')->assertOk()->json('result.tools'))->pluck('name')->all();

    expect($names(mcpKey($workspace)))->toBe(['get_workspace', 'search', 'list_issues', 'dismiss_issues', 'get_activity'])
        ->and($names(mcpKey($workspace, scopes: [Scopes::READ])))->toBe(['get_workspace', 'search', 'list_issues', 'get_activity'])
        ->and($names(mcpKey($workspace, wsToolsMember($workspace))))->toBe(['get_workspace', 'search', 'list_issues', 'get_activity'])
        ->and($names(mcpKey($workspace, wsToolsMember($workspace, WorkspaceRole::Member))))->toContain('dismiss_issues');

    $tools = collect(mcpRequest($workspace, mcpKey($workspace), 'tools/list')->json('result.tools'))->keyBy('name');
    expect($tools['dismiss_issues']['annotations'])->toMatchArray(['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true])
        ->and($tools['search']['inputSchema']['required'])->toBe(['query'])
        ->and($tools['get_activity']['annotations']['readOnlyHint'])->toBeTrue();
});

test('get_workspace counts what needs attention as the Home badge does', function () {
    [$workspace] = wsToolsWorkspace();

    $result = mcpCall($workspace, mcpKey($workspace), 'get_workspace');

    $badge = collect(wsToolsHomeIssues($workspace))->where('severity', '!=', 'notice')->count();
    expect($result['error'])->toBeFalse()
        ->and($badge)->toBe(3)
        ->and($result['data']['counts']['needsAttention'])->toBe($badge)
        ->and($result['data']['counts']['monitors'])->toBe(1);
});

test('search finds machines, services, monitors and workflows like ⌘K, with ids to act on', function () {
    [$workspace, $machine, $monitor] = wsToolsWorkspace();
    $key = mcpKey($workspace);

    $umami = mcpCall($workspace, $key, 'search', ['query' => 'UMAMI']);
    expect($umami['error'])->toBeFalse()
        ->and($umami['data']['query'])->toBe('umami')
        ->and($umami['data']['machines'])->toBe([])
        ->and($umami['data']['services'])->toHaveCount(1)
        ->and($umami['data']['services'][0])->toMatchArray([
            'key' => 'docker:abc123def456',
            'machine' => ['id' => $machine->id, 'name' => 'web-1'],
            'foundAs' => 'serveros-umami-umami-1',
            'shownAs' => 'Umami',
            'status' => 'exited',
            'url' => 'https://'.$workspace->host()."/machines/{$machine->id}/services/docker%3Aabc123def456",
        ])
        ->and($umami['data']['monitors'])->toBe([])
        ->and($umami['data']['workflows'][0])->toMatchArray(['name' => 'Redeploy umami', 'enabled' => true])
        ->and($umami['data']['total'])->toBe(2)
        ->and($umami['data']['more'])->toBe([]);

    // Machines by name or address; monitors by URL; the dashboard agrees.
    expect(mcpCall($workspace, $key, 'search', ['query' => '203.0.113'])['data']['machines'][0])->toMatchArray(['id' => $machine->id, 'name' => 'web-1', 'status' => 'online', 'environment' => 'production'])
        ->and(mcpCall($workspace, $key, 'search', ['query' => 'shop.example'])['data']['monitors'][0])->toMatchArray(['id' => $monitor->id, 'status' => 'down', 'muted' => false, 'target' => 'https://shop.example.com/', 'machineId' => $machine->id]);
    $this->actingAs($workspace->owner)->getJson('https://'.$workspace->host().'/search?q=umami')
        ->assertJsonPath('services.0.label', 'Umami')
        ->assertJsonCount(1, 'workflows');

    // The name someone gave a service counts.
    $machine->forceFill(['service_labels' => ['nginx.service' => 'Front door']])->save();
    expect(mcpCall($workspace, $key, 'search', ['query' => 'front'])['data']['services'][0])->toMatchArray(['key' => 'nginx.service', 'label' => 'Front door', 'name' => 'Front door', 'shownAs' => 'Front door']);
    $this->actingAs($workspace->owner)->getJson('https://'.$workspace->host().'/search?q=front')->assertJsonPath('services.0.label', 'Front door');

    // A muted or paused monitor says so.
    $monitor->forceFill(['muted_until' => now()->addHour()])->save();
    expect(mcpCall($workspace, $key, 'search', ['query' => 'shop'])['data']['monitors'][0])->toMatchArray(['status' => 'down', 'muted' => true]);
    $monitor->forceFill(['paused' => true])->save();
    expect(mcpCall($workspace, $key, 'search', ['query' => 'shop'])['data']['monitors'][0])->toMatchArray(['status' => 'paused', 'muted' => false]);

    // Only some kinds.
    $only = mcpCall($workspace, $key, 'search', ['query' => 'umami', 'kinds' => ['workflows']])['data'];
    expect(array_keys($only))->toBe(['query', 'workflows', 'more', 'total']);
});

test('search limits each kind and says when there are more', function () {
    [$workspace] = wsToolsWorkspace();
    $workspace->forceFill(['plan' => Plan::Business, 'trial_ends_at' => null])->save();
    foreach (range(1, 7) as $i) {
        Machine::factory()->for($workspace)->create(['name' => "db-{$i}"]);
    }
    $key = mcpKey($workspace);

    $default = mcpCall($workspace, $key, 'search', ['query' => 'db-'])['data'];
    expect($default['machines'])->toHaveCount(5)
        ->and(array_column($default['machines'], 'name'))->toBe(['db-1', 'db-2', 'db-3', 'db-4', 'db-5'])
        ->and($default['more'])->toBe(['machines']);

    $two = mcpCall($workspace, $key, 'search', ['query' => 'db-', 'limit' => 25])['data'];
    expect($two['machines'])->toHaveCount(7)->and($two['more'])->toBe([]);
});

test('search checks its arguments and stays inside the workspace', function () {
    [$workspace] = wsToolsWorkspace();
    [$other] = wsToolsWorkspace();
    $key = mcpKey($workspace);

    expect(mcpCall($workspace, $key, 'search', ['query' => '   ']))->toMatchArray(['error' => true])
        ->and(mcpCall($workspace, $key, 'search', [])['text'])->toContain('query')
        ->and(mcpCall($workspace, $key, 'search', ['query' => 'web', 'limit' => 26])['error'])->toBeTrue()
        ->and(mcpCall($workspace, $key, 'search', ['query' => 'web', 'kinds' => ['secrets']])['error'])->toBeTrue()
        ->and(mcpCall($workspace, $key, 'search', ['query' => str_repeat('a', 101)])['error'])->toBeTrue();

    // Both workspaces have a web-1; each key sees its own.
    $mine = mcpCall($workspace, $key, 'search', ['query' => 'web-1'])['data']['machines'];
    expect($mine)->toHaveCount(1)
        ->and($mine[0]['id'])->toBe($workspace->machines()->value('id'))
        ->and(mcpCall($other, mcpKey($other), 'search', ['query' => 'web-1'])['data']['machines'][0]['id'])->toBe($other->machines()->value('id'));

    // "%" and "_" are characters, not wildcards.
    $workspace->monitors()->create(['name' => 'my_shop', 'type' => 'tcp', 'host' => 'db.internal', 'port' => 5432, 'interval_seconds' => 60, 'timeout_seconds' => 10, 'status' => 'up']);
    $workspace->monitors()->create(['name' => 'myxshop', 'type' => 'tcp', 'host' => 'db2.internal', 'port' => 5432, 'interval_seconds' => 60, 'timeout_seconds' => 10, 'status' => 'up']);
    expect(mcpCall($workspace, $key, 'search', ['query' => '%'])['data']['total'])->toBe(0)
        ->and(array_column(mcpCall($workspace, $key, 'search', ['query' => 'my_shop'])['data']['monitors'], 'name'))->toBe(['my_shop'])
        ->and(mcpCall($workspace, $key, 'search', ['query' => 'DB2.INTERNAL'])['data']['monitors'][0])->toMatchArray(['name' => 'myxshop', 'target' => 'db2.internal:5432', 'type' => 'tcp']);
});

test('list_issues gives what the Home shows, with what each is about and what to do', function () {
    [$workspace, $machine, $monitor] = wsToolsWorkspace();
    $machine->forceFill(['disks' => [['mount' => '/', 'used' => 76 << 30, 'total' => 80 << 30]], 'disk_percent' => 95])->save();

    $result = mcpCall($workspace, mcpKey($workspace, wsToolsMember($workspace)), 'list_issues');
    expect($result['error'])->toBeFalse();
    $data = $result['data'];
    $home = wsToolsHomeIssues($workspace);

    expect(array_column($data['issues'], 'id'))->toBe(array_column($home, 'id'))
        ->and(array_column($data['issues'], 'title'))->toBe(array_column($home, 'title'))
        ->and(array_column($data['issues'], 'detail'))->toBe(array_column($home, 'detail'))
        ->and($data['needsAttention'])->toBe(4)
        ->and($data['counts'])->toBe(['critical' => 4, 'warning' => 0, 'notice' => 0])
        ->and($data['matching'])->toBe(4)
        ->and($data['truncated'])->toBeFalse();

    $byKind = collect($data['issues'])->keyBy('kind');
    expect($byKind->keys()->sort()->values()->all())->toBe(['machine.disk', 'monitor.down', 'service.crash_loop', 'service.down']);

    expect($byKind['monitor.down'])->toMatchArray([
        'id' => "monitor-{$monitor->id}-down",
        'severity' => 'critical',
        'title' => 'Shop is down',
        'monitor' => ['id' => $monitor->id, 'name' => 'Shop', 'target' => 'https://shop.example.com/'],
        'machine' => ['id' => $machine->id, 'name' => 'web-1'],
        'service' => null,
        'action' => 'Open monitor',
        'url' => 'https://'.$workspace->host()."/monitors/{$monitor->id}",
    ])->and($byKind['monitor.down']['fix'])->toContain('Returned 502.')
        ->and($byKind['monitor.down']['since'])->not->toBeNull();

    expect($byKind['service.down'])->toMatchArray([
        'id' => "service-{$machine->id}-docker:abc123def456-down",
        'title' => 'serveros-umami-umami-1 stopped on web-1',
        'service' => ['key' => 'docker:abc123def456', 'name' => 'serveros-umami-umami-1', 'url' => 'https://'.$workspace->host()."/machines/{$machine->id}/services/docker%3Aabc123def456"],
        'machine' => ['id' => $machine->id, 'name' => 'web-1'],
        'action' => 'See its logs',
    ])->and($byKind['service.down']['url'])->toBe('https://'.$workspace->host().'/logs?'.http_build_query(['machine' => $machine->id, 'service' => 'serveros-umami-umami-1']));

    expect($byKind['service.crash_loop']['service']['key'])->toBe('nginx.service')
        ->and($byKind['machine.disk']['fix'])->toContain('on / (4 GB left)')
        ->and($byKind['machine.disk']['monitor'])->toBeNull();
});

test('list_issues filters by severity and machine', function () {
    [$workspace, $machine, $monitor] = wsToolsWorkspace();
    $quiet = Machine::factory()->for($workspace)->create(['name' => 'staging-1', 'environment' => 'staging', 'last_backup_at' => now()]);
    $quiet->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()->subMinutes(10)])->save();
    $key = mcpKey($workspace);

    $all = mcpCall($workspace, $key, 'list_issues')['data'];
    expect($all['counts'])->toBe(['critical' => 3, 'warning' => 1, 'notice' => 0]);

    $critical = mcpCall($workspace, $key, 'list_issues', ['severity' => 'critical'])['data'];
    expect(array_unique(array_column($critical['issues'], 'severity')))->toBe(['critical'])
        ->and($critical['matching'])->toBe(3)
        // Counts are always of the whole list.
        ->and($critical['needsAttention'])->toBe(4);

    $staging = mcpCall($workspace, $key, 'list_issues', ['machine' => 'staging-1'])['data'];
    expect(array_column($staging['issues'], 'kind'))->toBe(['machine.offline'])
        ->and($staging['issues'][0]['fix'])->toContain('systemctl restart serverosd');

    // A monitor's problems belong to the machine it watches.
    $web = mcpCall($workspace, $key, 'list_issues', ['machine' => $machine->id])['data'];
    expect(array_column($web['issues'], 'id'))->toContain("monitor-{$monitor->id}-down")
        ->and($web['matching'])->toBe(3);

    $one = mcpCall($workspace, $key, 'list_issues', ['limit' => 1])['data'];
    expect($one['issues'])->toHaveCount(1)->and($one['truncated'])->toBeTrue()->and($one['matching'])->toBe(4);

    [$other] = wsToolsWorkspace();
    expect(mcpCall($workspace, $key, 'list_issues', ['machine' => $other->machines()->value('id')]))
        ->toMatchArray(['error' => true])
        ->and(mcpCall($workspace, $key, 'list_issues', ['severity' => 'meh'])['error'])->toBeTrue()
        ->and(mcpCall($workspace, $key, 'list_issues', ['machine' => ['web-1']])['error'])->toBeTrue();
});

test('maintenance and muted monitors show as notices, with nothing to fix', function () {
    [$workspace, $machine] = wsToolsWorkspace();
    $machine->forceFill(['maintenance_until' => now()->addHour(), 'maintenance_reason' => 'Kernel upgrade'])->save();

    $data = mcpCall($workspace, mcpKey($workspace), 'list_issues')['data'];
    expect($data['needsAttention'])->toBe(0)
        ->and($data['counts']['notice'])->toBe(4)
        ->and(collect($data['issues'])->firstWhere('kind', 'machine.maintenance')['fix'])->toStartWith('Nothing to fix')
        ->and(collect($data['issues'])->firstWhere('kind', 'machine.maintenance')['title'])->toStartWith('web-1 is in maintenance until')
        ->and(collect($data['issues'])->firstWhere('kind', 'monitor.down')['title'])->toBe('Shop is down (muted)');
});

test('backup problems name the machine and point at the backups page', function () {
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'db-1', 'environment' => 'production', 'last_backup_at' => null]);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'backup_schedule' => 'off', 'inventory' => ['services' => [
        ['key' => 'docker:pg', 'name' => 'postgres', 'status' => 'running', 'details' => ['managed' => 'true']],
    ]]])->save();
    $machine->backups()->create(['service' => 'docker:pg', 'service_name' => 'postgres', 'trigger' => 'manual', 'state' => 'failed'])
        ->forceFill(['error' => 'Destination refused the upload.', 'finished_at' => now()])->save();

    $issue = mcpCall($workspace, mcpKey($workspace), 'list_issues')['data']['issues'][0];
    expect($issue)->toMatchArray([
        'id' => "backup-{$machine->id}-failed",
        'kind' => 'backup.failed',
        'severity' => 'warning',
        'machine' => ['id' => $machine->id, 'name' => 'db-1'],
        'url' => 'https://'.$workspace->host().'/backups',
    ])->and($issue['detail'])->toContain('Destination refused the upload.');
});

test('dismiss_issues marks items as read for everyone, like the Home, and notes it in the activity', function () {
    [$workspace, $machine, $monitor] = wsToolsWorkspace();
    $member = wsToolsMember($workspace, WorkspaceRole::Member, ['name' => 'Grace']);
    $viewer = wsToolsMember($workspace);
    $key = mcpKey($workspace, $member);
    $down = "monitor-{$monitor->id}-down";

    $result = mcpCall($workspace, $key, 'dismiss_issues', ['ids' => [$down, 'machine-999999-offline']]);
    expect($result['error'])->toBeFalse()
        ->and($result['data'])->toMatchArray(['marked' => 1, 'notListed' => ['machine-999999-offline'], 'left' => 2, 'needsAttention' => 2]);

    // Gone from everyone's Home, and from list_issues.
    expect(array_column(wsToolsHomeIssues($workspace, $viewer), 'id'))->not->toContain($down)
        ->and(array_column(mcpCall($workspace, $key, 'list_issues')['data']['issues'], 'id'))->not->toContain($down);

    // As the dashboard records it, credited to the person, via MCP.
    $entry = Activity::query()->where('action', 'issues.dismiss')->sole();
    expect($entry->user_id)->toBe($member->id)
        ->and($entry->subject)->toBe('1 item')
        ->and($entry->machine_id)->toBeNull()
        // And the call that did it, to find it in the MCP call log.
        ->and($entry->meta)->toMatchArray(['via' => 'mcp', 'client' => 'Test key', 'tool' => 'dismiss_issues'])
        ->and($entry->meta['call'])->toBe(McpToolCall::query()->where('tool', 'dismiss_issues')->sole()->call_id);

    // Again: nothing more to mark, nothing more recorded.
    expect(mcpCall($workspace, $key, 'dismiss_issues', ['ids' => [$down]])['data'])->toMatchArray(['marked' => 0, 'notListed' => [$down]])
        ->and(Activity::query()->where('action', 'issues.dismiss')->count())->toBe(1);

    // Everything at once.
    expect(mcpCall($workspace, $key, 'dismiss_issues', ['all' => true])['data'])->toMatchArray(['marked' => 2, 'left' => 0, 'needsAttention' => 0])
        ->and(Activity::query()->where('action', 'issues.dismiss')->latest('id')->value('subject'))->toBe('2 items')
        ->and(wsToolsHomeIssues($workspace))->toBe([]);

    // It comes back when it happens again.
    $monitor->forceFill(['status_changed_at' => now()->addMinute()])->save();
    expect(array_column(wsToolsHomeIssues($workspace), 'id'))->toContain($down);
});

test('dismiss_issues needs a role that manages machines and a connection that may change things', function () {
    [$workspace, , $monitor] = wsToolsWorkspace();
    $ids = ['ids' => ["monitor-{$monitor->id}-down"]];
    $viewer = wsToolsMember($workspace);

    $denied = mcpCall($workspace, mcpKey($workspace, $viewer), 'dismiss_issues', $ids);
    expect($denied['error'])->toBeTrue()->and($denied['text'])->toContain('Connect and manage machines');

    $readOnly = mcpCall($workspace, mcpKey($workspace, scopes: [Scopes::READ]), 'dismiss_issues', $ids);
    expect($readOnly['error'])->toBeTrue()->and($readOnly['text'])->toContain('read-only');

    expect(Activity::query()->count())->toBe(0)
        ->and($workspace->fresh()->dismissed_issues)->toBeNull();

    // The dashboard draws the same line.
    $this->actingAs($viewer)
        ->post('https://'.$workspace->host().'/issues/dismiss', $ids)->assertForbidden();
});

test('dismiss_issues checks its arguments as the dashboard does', function () {
    [$workspace] = wsToolsWorkspace();
    $key = mcpKey($workspace);

    expect(mcpCall($workspace, $key, 'dismiss_issues')['text'])->toContain('ids from list_issues')
        ->and(mcpCall($workspace, $key, 'dismiss_issues', ['ids' => []])['error'])->toBeTrue()
        ->and(mcpCall($workspace, $key, 'dismiss_issues', ['ids' => array_fill(0, 201, 'x')])['error'])->toBeTrue()
        ->and(mcpCall($workspace, $key, 'dismiss_issues', ['ids' => [str_repeat('x', 301)]])['error'])->toBeTrue()
        ->and(mcpCall($workspace, $key, 'dismiss_issues', ['ids' => [['nested']]])['error'])->toBeTrue()
        ->and(mcpCall($workspace, $key, 'dismiss_issues', ['all' => false])['error'])->toBeTrue()
        ->and(Activity::query()->count())->toBe(0);
});

test('one workspace can’t mark another’s items as read', function () {
    [$workspace] = wsToolsWorkspace();
    [$other, , $theirs] = wsToolsWorkspace();

    $result = mcpCall($workspace, mcpKey($workspace), 'dismiss_issues', ['ids' => ["monitor-{$theirs->id}-down"]]);
    expect($result['data'])->toMatchArray(['marked' => 0, 'notListed' => ["monitor-{$theirs->id}-down"]])
        ->and($other->fresh()->dismissed_issues)->toBeNull()
        ->and(array_column(wsToolsHomeIssues($other), 'id'))->toContain("monitor-{$theirs->id}-down");
});

test('get_activity reads like the Activity page, and says what came through MCP', function () {
    [$workspace, $machine] = wsToolsWorkspace();
    $workspace->owner->forceFill(['name' => 'Ada Lovelace'])->save();
    Activity::record($workspace, 'service.restart', 'umami', $machine, $workspace->owner);
    Activity::system($workspace, 'machine.offline', 'web-1', $machine);
    Activity::record($workspace, 'monitor.mute', 'Shop', null, $workspace->owner, ['minutes' => 30]);
    $token = McpToken::issueKey($workspace, $workspace->owner, 'Claude Desktop', Scopes::ALL)[0];
    (new McpContext($workspace, $workspace->owner, $token))->record('issues.dismiss', '2 items');

    $result = mcpCall($workspace, mcpKey($workspace), 'get_activity');
    expect($result['error'])->toBeFalse();
    $entries = $result['data']['entries'];

    expect(array_column($entries, 'what'))->toBe([
        'Ada Lovelace marked as read 2 items in Needs attention',
        'Ada Lovelace muted the monitor Shop for 30 min',
        'web-1 stopped reporting',
        'Ada Lovelace restarted umami on web-1',
    ])
        ->and($entries[0])->toMatchArray(['action' => 'issues.dismiss', 'viaMcp' => true, 'client' => 'Claude Desktop', 'who' => ['id' => $workspace->owner_id, 'name' => 'Ada Lovelace', 'support' => false]])
        ->and($entries[2])->toMatchArray(['who' => null, 'viaMcp' => false, 'client' => null, 'tone' => 'bad', 'machine' => ['id' => $machine->id, 'name' => 'web-1']])
        ->and($entries[3])->toMatchArray(['verb' => 'restarted', 'subject' => 'umami'])
        ->and($result['data'])->toMatchArray(['hasMore' => false, 'nextCursor' => null, 'keepDays' => 90, 'url' => 'https://'.$workspace->host().'/activity']);

    // The dashboard's own words for the same entries.
    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/activity')
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.0.verb', $entries[0]['verb'])
            ->where('entries.1.after', $entries[1]['after'])
            ->where('entries.2.leads', true));
});

test('get_activity filters by machine, who, person, action, MCP and time', function () {
    [$workspace, $machine] = wsToolsWorkspace();
    $db = Machine::factory()->for($workspace)->create(['name' => 'db-1']);
    $grace = wsToolsMember($workspace, WorkspaceRole::Admin, ['name' => 'Grace Hopper', 'email' => 'grace@example.com']);
    $owner = $workspace->owner;

    $this->travelTo(now()->subDays(3));
    Activity::record($workspace, 'service.restart', 'umami', $machine, $owner);
    $this->travelBack();
    Activity::record($workspace, 'service.stop', 'postgres', $db, $grace);
    Activity::system($workspace, 'service.down', 'postgres', $db);
    Activity::record($workspace, 'monitor.add', 'Shop', null, $owner);
    (new McpContext($workspace, $grace, McpToken::issueKey($workspace, $grace, 'Cursor', Scopes::ALL)[0]))->record('machine.maintenance', 'web-1', $machine, ['minutes' => 60]);
    Activity::record($workspace, 'machine.maintenance_end', 'web-1', $machine, $owner);

    $key = mcpKey($workspace);
    $actions = fn (array $args) => array_column(mcpCall($workspace, $key, 'get_activity', $args)['data']['entries'], 'action');

    expect($actions(['machine' => 'db-1']))->toBe(['service.down', 'service.stop'])
        ->and($actions(['machine' => $machine->id]))->toBe(['machine.maintenance_end', 'machine.maintenance', 'service.restart'])
        ->and($actions(['who' => 'system']))->toBe(['service.down'])
        ->and($actions(['who' => 'people', 'action' => 'service']))->toBe(['service.stop', 'service.restart'])
        ->and($actions(['person' => 'grace@EXAMPLE.com']))->toBe(['machine.maintenance', 'service.stop'])
        ->and($actions(['person' => 'grace hopper']))->toBe(['machine.maintenance', 'service.stop'])
        ->and($actions(['person' => (string) $grace->id]))->toBe(['machine.maintenance', 'service.stop'])
        ->and($actions(['person' => 'me', 'action' => 'monitor']))->toBe(['monitor.add'])
        ->and($actions(['action' => 'machine.maintenance']))->toBe(['machine.maintenance_end', 'machine.maintenance'])
        ->and($actions(['action' => 'Machine.Maintenance_']))->toBe(['machine.maintenance_end'])
        ->and($actions(['action' => 'machine.maintenance%']))->toBe([])
        ->and($actions(['action' => 'service.']))->toBe(['service.down', 'service.stop', 'service.restart'])
        ->and($actions(['viaMcp' => true]))->toBe(['machine.maintenance'])
        ->and($actions(['viaMcp' => false]))->not->toContain('machine.maintenance')
        ->and($actions(['viaMcp' => false]))->toHaveCount(5)
        ->and($actions(['since' => now()->subDay()->toIso8601String()]))->not->toContain('service.restart')
        ->and($actions(['until' => now()->subDays(2)->toDateString()]))->toBe(['service.restart'])
        ->and($actions(['since' => now()->subDays(3)->toDateString(), 'until' => now()->subDays(3)->toDateString()]))->toBe(['service.restart']);

    $filtered = mcpCall($workspace, $key, 'get_activity', ['who' => 'people', 'machine' => 'db-1'])['data'];
    expect($filtered['url'])->toBe('https://'.$workspace->host()."/activity?who=people&machine={$db->id}");

    // Someone who left still counts, by name or email.
    $workspace->members()->detach($grace);
    expect($actions(['person' => 'Grace Hopper']))->toBe(['machine.maintenance', 'service.stop'])
        ->and($actions(['person' => $grace->email]))->toBe(['machine.maintenance', 'service.stop']);

    // By email only to those who could export them: a viewer's key gets nowhere that way.
    $viewer = wsToolsMember($workspace);
    expect(mcpCall($workspace, mcpKey($workspace, $viewer), 'get_activity', ['person' => $grace->email])['text'])->toContain('No one called')
        ->and(mcpCall($workspace, mcpKey($workspace, $viewer), 'get_activity', ['person' => 'Grace Hopper'])['data']['entries'])->toHaveCount(2);
});

test('get_activity pages through older entries with a cursor', function () {
    [$workspace, $machine] = wsToolsWorkspace();
    foreach (range(1, 7) as $i) {
        Activity::record($workspace, 'service.restart', "app-{$i}", $machine, $workspace->owner);
    }
    $key = mcpKey($workspace);

    $first = mcpCall($workspace, $key, 'get_activity', ['limit' => 3])['data'];
    expect(array_column($first['entries'], 'subject'))->toBe(['app-7', 'app-6', 'app-5'])
        ->and($first['hasMore'])->toBeTrue()
        ->and($first['nextCursor'])->toBe($first['entries'][2]['id']);

    $second = mcpCall($workspace, $key, 'get_activity', ['limit' => 3, 'before' => $first['nextCursor']])['data'];
    expect(array_column($second['entries'], 'subject'))->toBe(['app-4', 'app-3', 'app-2']);

    $last = mcpCall($workspace, $key, 'get_activity', ['limit' => 3, 'before' => $second['nextCursor']])['data'];
    expect(array_column($last['entries'], 'subject'))->toBe(['app-1'])
        ->and($last['hasMore'])->toBeFalse()
        ->and($last['nextCursor'])->toBeNull();

    // The default page is the dashboard's.
    foreach (range(1, 50) as $i) {
        Activity::record($workspace, 'monitor.add', "m-{$i}", null, $workspace->owner);
    }
    expect(mcpCall($workspace, $key, 'get_activity')['data']['entries'])->toHaveCount(50);
});

test('get_activity shows console commands only to people who can run them, and only this workspace', function () {
    [$workspace, $machine] = wsToolsWorkspace();
    [$other, $theirs] = wsToolsWorkspace();
    Activity::record($workspace, 'console.command', 'umami', $machine, $workspace->owner, ['command' => 'redis-cli FLUSHALL']);
    Activity::record($other, 'service.restart', 'secret-app', $theirs, $other->owner);

    $owner = mcpCall($workspace, mcpKey($workspace), 'get_activity')['data']['entries'];
    expect($owner)->toHaveCount(1)
        ->and($owner[0]['detail'])->toBe('redis-cli FLUSHALL')
        ->and($owner[0]['what'])->toBe($workspace->owner->name.' ran a command in umami on web-1');

    $viewer = mcpCall($workspace, mcpKey($workspace, wsToolsMember($workspace)), 'get_activity')['data']['entries'];
    expect($viewer[0]['detail'])->toBeNull();

    // Another workspace's machine and people aren't found here.
    expect(mcpCall($workspace, mcpKey($workspace), 'get_activity', ['machine' => $theirs->id])['error'])->toBeTrue()
        ->and(mcpCall($workspace, mcpKey($workspace), 'get_activity', ['person' => $other->owner->email])['text'])->toContain('No one called');
});

test('get_activity checks its arguments', function () {
    [$workspace] = wsToolsWorkspace();
    $key = mcpKey($workspace);
    $error = fn (array $args) => mcpCall($workspace, $key, 'get_activity', $args);

    expect($error(['since' => 'not a date'])['error'])->toBeTrue()
        ->and($error(['since' => '2026-05-02', 'until' => '2026-05-01'])['text'])->toContain('since is after until')
        ->and($error(['limit' => 101])['error'])->toBeTrue()
        ->and($error(['limit' => 0])['error'])->toBeTrue()
        ->and($error(['who' => 'robots'])['error'])->toBeTrue()
        ->and($error(['before' => 'abc'])['error'])->toBeTrue()
        ->and($error(['person' => ['x']])['error'])->toBeTrue()
        ->and($error(['person' => 'Nobody'])['text'])->toContain('No one called “Nobody”');

    $twins = [wsToolsMember($workspace, attributes: ['name' => 'Sam']), wsToolsMember($workspace, attributes: ['name' => 'Sam'])];
    expect($error(['person' => 'Sam'])['text'])->toContain('Several people are called “Sam”')
        ->and($error(['person' => 'Sam'])['text'])->toContain((string) $twins[0]->id);
});

test('the activity page and its export read the same after the wording moved', function () {
    [$workspace, $machine] = wsToolsWorkspace();
    Activity::record($workspace, 'machine.maintenance', 'web-1', $machine, $workspace->owner, ['minutes' => 90, 'reason' => 'Disk swap']);

    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/activity')
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.0.verb', 'started maintenance on')
            ->where('entries.0.after', 'for 1 h 30 min: Disk swap')
            ->where('entries.0.tone', 'warn'));

    $csv = $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/activity/export')->streamedContent();
    expect($csv)->toContain('started maintenance on web-1 for 1 h 30 min: Disk swap');
});

test('issue ids parse into what they are about', function () {
    $parse = Issues::parse(...);

    expect($parse('service-12-my-app.service-down'))->toBe(['kind' => 'service.down', 'machineId' => 12, 'monitorId' => null, 'serviceKey' => 'my-app.service'])
        ->and($parse('service-3-docker:4f2a-loop')['serviceKey'])->toBe('docker:4f2a')
        ->and($parse('machine-7-never')['kind'])->toBe('machine.never_connected')
        ->and($parse('monitor-9-certificate'))->toMatchArray(['kind' => 'monitor.certificate', 'monitorId' => 9])
        ->and($parse('backup-4-off'))->toMatchArray(['kind' => 'backup.off', 'machineId' => 4])
        ->and($parse('something-else')['kind'])->toBe('other');
});

test('the Home and the tools share one idea of what needs attention', function () {
    [$workspace] = wsToolsWorkspace();

    expect(array_column(app(WorkspaceHealth::class)->issues($workspace), 'id'))
        ->toEqualCanonicalizing(array_column(mcpCall($workspace, mcpKey($workspace), 'list_issues')['data']['issues'], 'id'));
});

test('blank optional arguments count as left out, as some clients send them', function () {
    [$workspace, $machine] = wsToolsWorkspace();
    Activity::record($workspace, 'service.restart', 'umami', $machine, $workspace->owner);
    (new McpContext($workspace, $workspace->owner, McpToken::issueKey($workspace, $workspace->owner, 'Cursor', Scopes::ALL)[0]))->record('monitor.add', 'Shop');
    // A staging server gone quiet: a warning, so a blank severity mustn't hide it.
    $staging = Machine::factory()->for($workspace)->create(['name' => 'staging-1', 'environment' => 'staging', 'last_backup_at' => now()]);
    $staging->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()->subMinutes(10)])->save();
    $key = mcpKey($workspace);

    $issues = mcpCall($workspace, $key, 'list_issues', ['severity' => '', 'machine' => '  ', 'limit' => ''])['data'];
    expect($issues['matching'])->toBe(4)
        ->and(array_count_values(array_column($issues['issues'], 'severity')))->toBe(['critical' => 3, 'warning' => 1]);

    $activity = mcpCall($workspace, $key, 'get_activity', ['since' => '', 'until' => ' ', 'viaMcp' => '', 'limit' => '', 'person' => '', 'machine' => '', 'action' => '', 'who' => '', 'before' => null]);
    expect($activity['error'])->toBeFalse()
        ->and(array_column($activity['data']['entries'], 'action'))->toBe(['monitor.add', 'service.restart']);

    $search = mcpCall($workspace, $key, 'search', ['query' => 'umami', 'limit' => '', 'kinds' => []])['data'];
    expect($search['services'])->toHaveCount(1)
        ->and($search['workflows'])->toHaveCount(1);

    expect(mcpCall($workspace, $key, 'search', ['query' => ''])['text'])->toContain('query can’t be blank')
        ->and(mcpCall($workspace, $key, 'dismiss_issues', ['ids' => [], 'all' => ''])['error'])->toBeTrue()
        ->and(Activity::query()->where('action', 'issues.dismiss')->exists())->toBeFalse();
});

test('anyone in the workspace can search and read, even on a read-only key, but not into another workspace', function () {
    [$workspace, $machine] = wsToolsWorkspace();
    [$other, $theirs] = wsToolsWorkspace();
    $theirs->forceFill(['name' => 'their-box', 'service_labels' => ['nginx.service' => 'Their front door']])->save();
    $viewer = mcpKey($workspace, wsToolsMember($workspace), [Scopes::READ]);

    $found = mcpCall($workspace, $viewer, 'search', ['query' => 'umami'])['data'];
    expect(array_column($found['services'], 'machine'))->toBe([['id' => $machine->id, 'name' => 'web-1']])
        ->and(mcpCall($workspace, $viewer, 'search', ['query' => 'their'])['data']['total'])->toBe(0)
        ->and(mcpCall($other, mcpKey($other), 'search', ['query' => 'their'])['data']['total'])->toBe(2);

    $about = mcpCall($workspace, $viewer, 'get_workspace')['data'];
    expect($about['you']['role'])->toBe('Viewer')
        ->and($about['connection'])->toMatchArray(['canMakeChanges' => false, 'canRunCommands' => false])
        ->and($about['counts']['machines'])->toBe(1)
        ->and(mcpCall($workspace, $viewer, 'list_issues')['error'])->toBeFalse()
        ->and(mcpCall($workspace, $viewer, 'get_activity')['error'])->toBeFalse();
});

test('dismiss_issues with all and nothing listed marks nothing and records nothing', function () {
    $workspace = Workspace::factory()->create();

    expect(mcpCall($workspace, mcpKey($workspace), 'dismiss_issues', ['all' => true])['data'])->toMatchArray(['marked' => 0, 'notListed' => [], 'left' => 0, 'needsAttention' => 0])
        ->and(Activity::query()->count())->toBe(0);
});

test('MCP keys made and revoked read as sentences, on the page and through get_activity', function () {
    $workspace = Workspace::factory()->create();
    $workspace->owner->forceFill(['name' => 'Ada'])->save();
    Activity::record($workspace, 'mcp.key_create', 'Laptop', null, $workspace->owner, ['scopes' => Scopes::ALL]);
    Activity::record($workspace, 'mcp.revoke', 'Laptop', null, $workspace->owner, ['kind' => 'key', 'owner' => $workspace->owner_id]);
    Activity::record($workspace, 'mcp.revoke', 'Claude', null, $workspace->owner, ['kind' => 'oauth', 'owner' => $workspace->owner_id]);

    expect(array_column(mcpCall($workspace, mcpKey($workspace), 'get_activity')['data']['entries'], 'what'))->toBe([
        'Ada disconnected Claude from MCP',
        'Ada revoked the MCP key Laptop',
        'Ada created the MCP key Laptop',
    ]);

    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/activity')
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.0.verb', 'disconnected')
            ->where('entries.1.verb', 'revoked the MCP key')
            ->where('entries.2.verb', 'created the MCP key'));
});

test('a Pterodactyl container whose Wings files are gone needs repairing', function () {
    [$workspace, $machine] = wsToolsWorkspace();
    $inventory = $machine->inventory;
    $inventory['services'][] = ['key' => 'docker:82cfe2569cd4', 'name' => '10279aa4-91dd', 'kind' => 'container', 'manager' => 'docker', 'status' => 'exited',
        'ports' => [25567], 'details' => ['managed' => 'true', 'needs_repair' => 'wings']];
    $machine->forceFill(['inventory' => $inventory])->save();

    $issue = collect(mcpCall($workspace, mcpKey($workspace, wsToolsMember($workspace)), 'list_issues')['data']['issues'])
        ->firstWhere('kind', 'service.needs_repair');

    expect($issue)->toMatchArray([
        'id' => "service-{$machine->id}-docker:82cfe2569cd4-repair",
        'severity' => 'warning',
        'title' => '10279aa4-91dd needs repairing before it can start on web-1',
        'action' => 'Repair it',
        'url' => 'https://'.$workspace->host()."/machines/{$machine->id}/services/docker%3A82cfe2569cd4",
    ])->and($issue['service']['key'])->toBe('docker:82cfe2569cd4')
        ->and($issue['fix'])->toContain('repair_service');
});
