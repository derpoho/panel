<?php

use App\Enums\Plan;
use App\Mcp\Scopes;
use App\Mcp\Tools\StatusPageTools;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Monitor;
use App\Models\ServiceGroup;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceStatusPage;
use App\Support\Monitors\HostResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(fn () => config()->set('mcp.groups', [StatusPageTools::class]));

const STATUS_TOOLS_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

/** An inventory entry. */
function statusToolsService(string $key, string $name, string $status = 'running', array $details = []): array
{
    return ['key' => $key, 'name' => $name, 'kind' => 'app', 'manager' => str_starts_with($key, 'docker:') ? 'docker' : 'systemd', 'status' => $status, 'ports' => [], 'confidence' => 90, 'details' => $details];
}

/** An online machine with a complete scan. */
function statusToolsMachine(Workspace $workspace, string $name = 'web-1', ?array $services = null): Machine
{
    $machine = Machine::factory()->for($workspace)->create(['name' => $name]);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => $services ?? [
            statusToolsService('docker:aaa', 'serveros-umami-umami-1'),
            statusToolsService('docker:bbb', 'serveros-umami-db-1'),
            statusToolsService('systemd:nginx.service', 'nginx.service', 'failed'),
            statusToolsService('docker:ccc', 'postgres'),
            statusToolsService('docker:ddd', 'redis'),
        ]],
    ])->save();

    return $machine;
}

/** @return array{0: Workspace, 1: Machine, 2: string} */
function statusToolsSetup(Plan $plan = Plan::Pro, string $workspaceName = 'Acme'): array
{
    $workspace = Workspace::factory()->onPlan($plan)->create(['name' => $workspaceName]);

    return [$workspace, statusToolsMachine($workspace), mcpKey($workspace)];
}

function statusToolsViewer(Workspace $workspace): string
{
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    return mcpKey($workspace, $viewer);
}

function statusToolsMonitor(Workspace $workspace, Machine $machine, array $attributes): Monitor
{
    return $workspace->monitors()->create([
        'machine_id' => $machine->id, 'type' => 'http', 'interval_seconds' => 60, 'timeout_seconds' => 10, 'alerts' => true, 'status' => 'up', ...$attributes,
    ]);
}

/** A saved page for the machine. */
function statusToolsPage(Machine $machine, array $attributes = []): StatusPage
{
    $page = new StatusPage(['slug' => Str::slug($machine->name), 'enabled' => true, 'public' => true, 'include_new' => true, 'show_uptime' => true, ...$attributes]);
    $page->machine_id = $machine->id;
    $page->save();

    return $page;
}

/** @return list<string> component labels, section by section */
function statusToolsSections(array $preview): array
{
    return array_map(fn (array $s) => [$s['name'], array_column($s['components'], 'label')], $preview['sections']);
}

test('read tools are for everyone, changes need a write key and a role that manages machines', function () {
    [$workspace] = statusToolsSetup();

    $all = collect(mcpRequest($workspace, mcpKey($workspace), 'tools/list')->json('result.tools'))->keyBy('name');
    expect($all->keys()->all())->toEqualCanonicalizing([
        'list_status_pages', 'get_status_page', 'update_status_page', 'arrange_status_page',
        'preview_status_page', 'get_fleet_status_page', 'update_fleet_status_page',
        'list_status_incidents', 'post_status_incident', 'update_status_incident',
    ])
        ->and($all['arrange_status_page']['annotations'])->toMatchArray(['readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => true])
        ->and($all['update_status_page']['annotations'])->toMatchArray(['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true])
        ->and($all['get_status_page']['annotations']['readOnlyHint'])->toBeTrue();

    $reads = ['list_status_pages', 'get_status_page', 'preview_status_page', 'get_fleet_status_page', 'list_status_incidents'];
    $readOnly = collect(mcpRequest($workspace, mcpKey($workspace, scopes: [Scopes::READ]), 'tools/list')->json('result.tools'))->pluck('name');
    $viewer = collect(mcpRequest($workspace, statusToolsViewer($workspace), 'tools/list')->json('result.tools'))->pluck('name');
    expect($readOnly->all())->toEqualCanonicalizing($reads)
        ->and($viewer->all())->toEqualCanonicalizing($reads);

    expect(mcpCall($workspace, mcpKey($workspace, scopes: [Scopes::READ]), 'update_status_page', ['machine' => 'web-1', 'title' => 'x'])['text'])->toContain('read-only')
        ->and(mcpCall($workspace, statusToolsViewer($workspace), 'arrange_status_page', ['machine' => 'web-1', 'sections' => []])['text'])->toContain('doesn’t allow that')
        ->and(StatusPage::query()->count())->toBe(0);
});

test('list_status_pages shows each machine’s page and the fleet page', function () {
    [$workspace, $web, $key] = statusToolsSetup(Plan::Business);
    statusToolsMachine($workspace, 'db-1');
    statusToolsPage($web, ['title' => 'Acme status', 'services' => ['docker:bbb' => ['shown' => false, 'label' => null]]]);
    ServiceGroup::put($web, new ServiceGroup, 'Web', ['docker:aaa']);

    $data = mcpCall($workspace, $key, 'list_status_pages')['data'];
    $rows = collect($data['machines'])->keyBy('machine.name');

    expect($data['planAllows'])->toBe(['statusPages' => true, 'fleetStatusPage' => true])
        ->and($data['total'])->toBe(2)
        ->and($rows['db-1'])->toMatchArray(['exists' => false, 'enabled' => false, 'live' => false])
        ->and($rows['web-1'])->toMatchArray([
            'exists' => true, 'enabled' => true, 'live' => true, 'public' => true, 'slug' => 'web-1', 'title' => 'Acme status',
            'url' => rtrim((string) config('app.url'), '/').'/status/web-1',
            'badgeUrl' => rtrim((string) config('app.url'), '/').'/status/web-1/badge.svg',
            'shows' => ['services' => 4, 'monitors' => 0, 'sections' => 1],
        ])
        ->and($rows['web-1']['overall']['label'])->toBe('Partial outage')
        ->and($data['fleet'])->toMatchArray(['exists' => false, 'available' => true]);

    $fleet = new WorkspaceStatusPage(['slug' => 'acme', 'enabled' => true, 'public' => false, 'show_uptime' => true]);
    $fleet->workspace_id = $workspace->id;
    $fleet->save();

    $data = mcpCall($workspace, $key, 'list_status_pages', ['only_existing' => true])['data'];
    expect(collect($data['machines'])->pluck('machine.name')->all())->toBe(['web-1'])
        ->and($data['fleet'])->toMatchArray(['exists' => true, 'live' => true, 'public' => false, 'slug' => 'acme', 'title' => 'Acme', 'everyMachine' => true]);

    $data = mcpCall($workspace, $key, 'list_status_pages', ['limit' => 1])['data'];
    expect($data['machines'])->toHaveCount(1)->and($data['truncated'])->toBeTrue();

    // Pro: no fleet page.
    [$pro, , $proKey] = statusToolsSetup(Plan::Pro, 'Pro Co');
    expect(mcpCall($pro, $proKey, 'list_status_pages')['data']['fleet'])->toMatchArray(['exists' => false, 'available' => false]);
});

test('get_status_page shows the draft a machine without a page starts from: empty, until things are picked', function () {
    [$workspace, $machine, $key] = statusToolsSetup();
    $api = statusToolsMonitor($workspace, $machine, ['name' => 'API', 'url' => 'https://api.example.com/']);
    statusToolsMonitor($workspace, $machine, ['name' => 'Old', 'url' => 'https://old.example.com/', 'paused' => true]);

    $data = mcpCall($workspace, mcpKey($workspace, statusToolsViewer($workspace) ? $workspace->owner : null), 'get_status_page', ['machine' => 'web-1'])['data'];

    expect($data['planAllows'])->toBeTrue()
        ->and($data['page'])->toMatchArray(['exists' => false, 'enabled' => false, 'live' => false, 'public' => true, 'slug' => 'web-1', 'includeNew' => false, 'showUptime' => true])
        ->and($data['note'])->toContain('no status page yet')
        ->and(collect($data['services'])->pluck('suggested', 'key')->all())->toBe([
            'docker:aaa' => 'Umami', 'docker:bbb' => 'Umami Db', 'systemd:nginx.service' => 'Nginx', 'docker:ccc' => 'Postgres', 'docker:ddd' => 'Redis',
        ])
        // Nothing goes public until it's picked.
        ->and(collect($data['services'])->every(fn ($s) => $s['shown'] === false && $s['label'] === null && $s['section'] === null))->toBeTrue()
        // Paused monitors aren't listed at all.
        ->and($data['monitors'])->toHaveCount(1)
        ->and($data['monitors'][0])->toMatchArray(['key' => 'monitor:'.$api->id, 'name' => 'API', 'target' => 'https://api.example.com/', 'shown' => false])
        ->and(statusToolsSections($data['preview']))->toBe([])
        ->and($data['preview'])->not->toHaveKey('days')
        ->and($data['settingsUrl'])->toEndWith("/machines/{$machine->id}/settings/status");

    // Nothing was saved by looking.
    expect(StatusPage::query()->count())->toBe(0);
});

test('get_status_page shows what a saved page shows, with labels and sections', function () {
    [$workspace, $machine, $key] = statusToolsSetup();
    statusToolsPage($machine, ['title' => 'Acme', 'services' => [
        'docker:aaa' => ['shown' => true, 'label' => 'Analytics'],
        'docker:bbb' => ['shown' => false, 'label' => null],
    ]]);
    $web = ServiceGroup::put($machine, new ServiceGroup, 'Web', ['docker:aaa', 'systemd:nginx.service']);

    $data = mcpCall($workspace, statusToolsViewer($workspace), 'get_status_page', ['machine' => (string) $machine->id])['data'];
    $services = collect($data['services'])->keyBy('key');

    expect($data['page'])->toMatchArray(['exists' => true, 'enabled' => true, 'live' => true, 'title' => 'Acme'])
        ->and($services['docker:aaa'])->toMatchArray(['shown' => true, 'label' => 'Analytics', 'section' => 'Web', 'panelName' => 'serveros-umami-umami-1'])
        ->and($services['docker:bbb'])->toMatchArray(['shown' => false, 'section' => null])
        ->and($data['sections'])->toBe([[
            'id' => $web->id, 'name' => 'Web', 'services' => ['docker:aaa', 'systemd:nginx.service'], 'onPage' => ['docker:aaa', 'systemd:nginx.service'], 'iconUrl' => null,
        ]])
        ->and(statusToolsSections($data['preview']))->toBe([['Web', ['Analytics', 'Nginx']], [null, ['Postgres', 'Redis']]]);
});

test('machines are found by id or name only within the workspace', function () {
    [$workspace, , $key] = statusToolsSetup();
    [, $other] = statusToolsSetup(Plan::Pro, 'Other');

    foreach (['get_status_page', 'preview_status_page'] as $tool) {
        expect(mcpCall($workspace, $key, $tool, ['machine' => (string) $other->id])['text'])->toContain('No machine');
    }
    expect(mcpCall($workspace, $key, 'update_status_page', ['machine' => $other->id, 'enabled' => false])['text'])->toContain('No machine')
        ->and(mcpCall($workspace, $key, 'arrange_status_page', ['machine' => $other->id, 'sections' => []])['text'])->toContain('No machine')
        ->and(StatusPage::query()->count())->toBe(0);
});

test('update_status_page creates the page, changes only what it’s given, and is noted like the dashboard', function () {
    [$workspace, $machine, $key] = statusToolsSetup();

    $result = mcpCall($workspace, $key, 'update_status_page', ['machine' => 'web-1', 'title' => '  Acme status  ', 'services' => [
        ['service' => 'docker:aaa', 'label' => 'Analytics'],
        ['service' => 'serveros-umami-db-1', 'shown' => false],
    ]]);
    expect($result['error'])->toBeFalse()
        ->and($result['data'])->toMatchArray(['created' => true])
        ->and($result['data']['changed'])->toEqualCanonicalizing(['title', 'services'])
        ->and($result['data']['page'])->toMatchArray(['exists' => true, 'enabled' => false, 'title' => 'Acme status', 'slug' => 'web-1']);

    $page = $machine->fresh()->statusPage;
    expect($page->enabled)->toBeFalse()
        ->and($page->services)->toBe([
            'docker:aaa' => ['shown' => true, 'label' => 'Analytics'],
            'docker:bbb' => ['shown' => false, 'label' => null],
        ]);
    $activity = Activity::query()->sole();
    expect($activity->action)->toBe('status_page.update')
        ->and($activity->subject)->toBe('Acme status')
        ->and($activity->machine_id)->toBe($machine->id)
        ->and($activity->user_id)->toBe($workspace->owner->id)
        ->and($activity->meta)->toMatchArray(['via' => 'mcp']);

    // Unlisted entries keep their settings; an empty label goes back to the suggested name.
    mcpCall($workspace, $key, 'update_status_page', ['machine' => 'web-1', 'services' => [
        ['service' => 'nginx', 'label' => 'Web server'],
    ]]);
    mcpCall($workspace, $key, 'update_status_page', ['machine' => 'web-1', 'services' => [
        ['service' => 'docker:aaa', 'label' => ''],
    ]]);
    expect($page->fresh()->services)->toBe([
        'docker:aaa' => ['shown' => true, 'label' => null],
        'docker:bbb' => ['shown' => false, 'label' => null],
        'systemd:nginx.service' => ['shown' => true, 'label' => 'Web server'],
    ]);

    // Publishing and unpublishing are noted as such.
    $published = mcpCall($workspace, $key, 'update_status_page', ['machine' => 'web-1', 'enabled' => true, 'public' => false]);
    expect($published['data']['changed'])->toEqualCanonicalizing(['enabled', 'public'])
        ->and($published['data']['page']['live'])->toBeTrue()
        // Only what was picked: services nobody chose stay off a new page.
        ->and(statusToolsSections($published['data']['preview']))->toBe([[null, ['Umami', 'Web server']]]);
    mcpCall($workspace, $key, 'update_status_page', ['machine' => 'web-1', 'enabled' => false]);
    expect(Activity::query()->orderBy('id')->pluck('action')->all())->toBe([
        'status_page.update', 'status_page.update', 'status_page.update', 'status_page.publish', 'status_page.unpublish',
    ]);

    // The same again changes nothing and notes nothing.
    $same = mcpCall($workspace, $key, 'update_status_page', ['machine' => 'web-1', 'enabled' => false, 'title' => 'Acme status']);
    expect($same['data']['changed'])->toBe([])
        ->and($same['data']['notes'][0])->toContain('Nothing changed')
        ->and(Activity::query()->count())->toBe(5);
});

test('update_status_page sets notices, clears fields and handles monitors', function () {
    [$workspace, $machine, $key] = statusToolsSetup();
    $shop = statusToolsMonitor($workspace, $machine, ['name' => 'Shop', 'url' => 'https://shop.example.com/']);
    statusToolsPage($machine, ['description' => 'Our servers']);

    $result = mcpCall($workspace, $key, 'update_status_page', ['machine' => 'web-1', 'notice' => 'Upgrading the database', 'description' => '', 'services' => [
        ['service' => 'monitor:'.$shop->id, 'label' => 'Storefront'],
        ['service' => 'monitor:shop', 'shown' => true],
    ]]);
    expect($result['text'])->toContain('listed twice');

    $result = mcpCall($workspace, $key, 'update_status_page', ['machine' => 'web-1', 'notice' => 'Upgrading the database', 'description' => '', 'services' => [
        ['service' => 'monitor:Shop', 'label' => 'Storefront'],
    ]]);
    $page = $machine->fresh()->statusPage;
    expect($result['error'])->toBeFalse()
        ->and($page->notice)->toBe('Upgrading the database')
        ->and($page->notice_kind)->toBe('info')
        ->and($page->description)->toBeNull()
        ->and($page->services['monitor:'.$shop->id])->toBe(['shown' => true, 'label' => 'Storefront'])
        ->and($result['data']['preview']['notice'])->toBe(['text' => 'Upgrading the database', 'kind' => 'info'])
        ->and($result['data']['preview']['sections'][0])->toMatchArray(['name' => 'Websites'])
        ->and($result['data']['preview']['sections'][0]['components'][0])->toMatchArray(['key' => 'monitor:'.$shop->id, 'label' => 'Storefront', 'status' => 'operational']);

    mcpCall($workspace, $key, 'update_status_page', ['machine' => 'web-1', 'notice_kind' => 'incident']);
    expect($page->fresh()->notice_kind)->toBe('incident');
    mcpCall($workspace, $key, 'update_status_page', ['machine' => 'web-1', 'notice' => '']);
    expect($page->fresh()->notice)->toBeNull()->and($page->fresh()->notice_kind)->toBeNull();

    expect(mcpCall($workspace, $key, 'update_status_page', ['machine' => 'web-1', 'services' => [['service' => 'monitor:999']]])['text'])->toContain('No monitor');
});

test('turning include_new off keeps what shows now and hides what comes later', function () {
    [$workspace, $machine, $key] = statusToolsSetup();
    statusToolsPage($machine, ['services' => ['docker:bbb' => ['shown' => false, 'label' => 'DB']]]);

    mcpCall($workspace, $key, 'update_status_page', ['machine' => 'web-1', 'include_new' => false]);
    $page = $machine->fresh()->statusPage;
    expect($page->include_new)->toBeFalse()
        ->and($page->services)->toMatchArray([
            'docker:aaa' => ['shown' => true, 'label' => null],
            'docker:bbb' => ['shown' => false, 'label' => 'DB'],
            'docker:ddd' => ['shown' => true, 'label' => null],
        ]);

    // A new service arrives: hidden, while the others still show.
    $inventory = $machine->inventory;
    $inventory['services'][] = statusToolsService('docker:eee', 'grafana');
    $machine->forceFill(['inventory' => $inventory])->save();

    $services = collect(mcpCall($workspace, $key, 'get_status_page', ['machine' => 'web-1'])['data']['services'])->pluck('shown', 'key');
    expect($services->all())->toBe(['docker:aaa' => true, 'docker:bbb' => false, 'systemd:nginx.service' => true, 'docker:ccc' => true, 'docker:ddd' => true, 'docker:eee' => false]);
});

test('settings for services that are gone are dropped after a complete scan', function () {
    [$workspace, $machine, $key] = statusToolsSetup();
    statusToolsPage($machine, ['services' => ['docker:gone' => ['shown' => true, 'label' => 'Old'], 'docker:aaa' => ['shown' => false, 'label' => null]]]);

    mcpCall($workspace, $key, 'update_status_page', ['machine' => 'web-1', 'title' => 'Acme']);
    expect($machine->fresh()->statusPage->services)->toBe(['docker:aaa' => ['shown' => false, 'label' => null]]);
});

test('update_status_page checks settings as the dashboard does', function () {
    [$workspace, $machine, $key] = statusToolsSetup();
    [, $other] = statusToolsSetup(Plan::Pro, 'Other');
    statusToolsPage($other, ['slug' => 'taken']);
    $fleet = new WorkspaceStatusPage(['slug' => 'fleet-page', 'enabled' => false, 'public' => true, 'show_uptime' => true]);
    $fleet->workspace_id = $other->workspace_id;
    $fleet->save();

    $call = fn (array $args) => mcpCall($workspace, $key, 'update_status_page', ['machine' => 'web-1', ...$args]);

    expect($call(['slug' => 'Not OK!'])['text'])->toContain('Use lowercase letters, numbers and single dashes.')
        ->and($call(['slug' => 'taken'])['text'])->toContain('That address is taken.')
        ->and($call(['slug' => 'fleet-page'])['text'])->toContain('That address is taken.')
        ->and($call(['slug' => 'ab'])['text'])->toContain('at least 3')
        ->and($call(['title' => str_repeat('x', 81)])['text'])->toContain('80')
        ->and($call(['notice_kind' => 'party', 'notice' => 'Hi'])['text'])->toContain('notice kind')
        ->and($call(['enabled' => 'yes please'])['error'])->toBeTrue()
        ->and($call(['services' => [['service' => 'docker:aaa', 'label' => str_repeat('x', 61)]]])['text'])->toContain('60')
        ->and($call(['services' => [['service' => 'docker:aaa', 'visible' => false]]])['error'])->toBeTrue()
        ->and($call(['services' => [['service' => 'mystery']]])['text'])->toContain('No service “mystery”')
        ->and($call(['services' => [['service' => 'docker:aaa'], ['service' => 'umami-umami']]])['error'])->toBeTrue()
        ->and($call([])['text'])->toContain('Nothing to change')
        ->and(StatusPage::query()->where('machine_id', $machine->id)->exists())->toBeFalse();

    expect($call(['slug' => 'web-one'])['data']['page']['slug'])->toBe('web-one');
});

test('status pages are a Pro feature', function () {
    [$workspace, $machine, $key] = statusToolsSetup(Plan::Essential);

    expect(mcpCall($workspace, $key, 'update_status_page', ['machine' => 'web-1', 'enabled' => true])['text'])->toContain('Status pages come with Pro')
        ->and(StatusPage::query()->count())->toBe(0);

    // A page that stays off can be prepared, as the settings form's save allows.
    $result = mcpCall($workspace, $key, 'update_status_page', ['machine' => 'web-1', 'title' => 'Later']);
    expect($result['error'])->toBeFalse()
        ->and($result['data']['page']['live'])->toBeFalse();

    // A page switched on before the plan dropped has to be switched off to be changed.
    $machine->statusPage()->update(['enabled' => true]);
    expect(mcpCall($workspace, $key, 'update_status_page', ['machine' => 'web-1', 'title' => 'Again'])['text'])->toContain('still switched on')
        ->and(mcpCall($workspace, $key, 'update_status_page', ['machine' => 'web-1', 'enabled' => false])['error'])->toBeFalse();
});

test('arrange_status_page organises the page into sections and groups the dashboard the same way', function () {
    [$workspace, $machine, $key] = statusToolsSetup();
    statusToolsPage($machine, ['title' => 'Acme']);

    $result = mcpCall($workspace, $key, 'arrange_status_page', [
        'machine' => 'web-1',
        'sections' => [
            ['name' => 'Web', 'services' => ['docker:aaa', 'nginx']],
            ['name' => ' Data ', 'services' => ['postgres', 'redis']],
        ],
        'labels' => [['service' => 'docker:aaa', 'label' => 'Analytics'], ['service' => 'docker:ccc', 'label' => 'Database']],
        'hide_unlisted' => true,
    ]);

    expect($result['error'])->toBeFalse()
        ->and($result['data']['created'])->toBeFalse()
        ->and($result['data']['changed'])->toEqualCanonicalizing(['services', 'sections'])
        // Sections sort by name.
        ->and(statusToolsSections($result['data']['preview']))->toBe([['Data', ['Database', 'Redis']], ['Web', ['Analytics', 'Nginx']]])
        ->and(collect($result['data']['sections'])->pluck('services', 'name')->all())->toBe(['Data' => ['docker:ccc', 'docker:ddd'], 'Web' => ['docker:aaa', 'systemd:nginx.service']])
        ->and($result['data']['notes'][0])->toBe('On the page, top to bottom: “Data”, “Web”.');

    expect(ServiceGroup::query()->where('machine_id', $machine->id)->orderBy('name')->pluck('services', 'name')->all())->toBe([
        'Data' => ['docker:ccc', 'docker:ddd'],
        'Web' => ['docker:aaa', 'systemd:nginx.service'],
    ])
        ->and($machine->fresh()->statusPage->services['docker:bbb'])->toBe(['shown' => false, 'label' => null])
        ->and(Activity::query()->where('action', 'status_page.update')->count())->toBe(1);

    // The same again changes nothing.
    $again = mcpCall($workspace, $key, 'arrange_status_page', [
        'machine' => 'web-1',
        'sections' => [['name' => 'web', 'services' => ['docker:aaa', 'nginx']], ['name' => 'Data', 'services' => ['postgres', 'redis']]],
    ]);
    expect($again['data']['changed'])->toBe(['sections'])
        ->and(ServiceGroup::query()->pluck('name')->sort()->values()->all())->toBe(['Data', 'web']);
    $again = mcpCall($workspace, $key, 'arrange_status_page', [
        'machine' => 'web-1',
        'sections' => [['name' => 'web', 'services' => ['docker:aaa', 'nginx']], ['name' => 'Data', 'services' => ['postgres', 'redis']]],
    ]);
    expect($again['data']['changed'])->toBe([])
        ->and(Activity::query()->count())->toBe(2);
});

test('arrange_status_page reuses groups by name, moves services between them, and keeps unlisted ones unless told', function () {
    [$workspace, $machine, $key] = statusToolsSetup();
    Storage::fake('public');
    Storage::disk('public')->put('service-icons/old.png', 'icon');
    $web = ServiceGroup::put($machine, new ServiceGroup, 'Web', ['docker:aaa']);
    $web->forceFill(['icon_path' => 'service-icons/old.png'])->save();
    $cache = ServiceGroup::put($machine, new ServiceGroup, 'Cache', ['docker:ddd', 'docker:bbb']);

    $result = mcpCall($workspace, $key, 'arrange_status_page', [
        'machine' => 'web-1',
        'sections' => [['name' => 'WEB', 'services' => ['docker:aaa', 'redis']]],
    ]);

    expect($result['error'])->toBeFalse()
        ->and($result['data']['created'])->toBeTrue()
        ->and($web->fresh()->name)->toBe('WEB')
        ->and($web->fresh()->services)->toBe(['docker:aaa', 'docker:ddd'])
        ->and($web->fresh()->icon_path)->toBe('service-icons/old.png')
        ->and($cache->fresh()->services)->toBe(['docker:bbb'])
        // A draft is made, switched off.
        ->and($machine->fresh()->statusPage->enabled)->toBeFalse()
        // A new page shows what was arranged onto it, nothing else.
        ->and(statusToolsSections($result['data']['preview']))->toBe([['WEB', ['Umami', 'Redis']]])
        ->and(collect($result['data']['notes'])->implode(' '))->toContain('switched off');

    // Removing the other groups needs the machine's name.
    $remove = ['machine' => 'web-1', 'sections' => [['name' => 'Web', 'services' => ['docker:aaa']]], 'remove_other_groups' => true];
    Storage::disk('public')->put('service-icons/cache.png', 'icon');
    $cache->forceFill(['icon_path' => 'service-icons/cache.png'])->save();
    expect(mcpCall($workspace, $key, 'arrange_status_page', $remove)['text'])->toContain('needs confirm set to the machine’s name, “web-1”')
        ->and(mcpCall($workspace, $key, 'arrange_status_page', [...$remove, 'confirm' => 'web'])['error'])->toBeTrue()
        ->and(ServiceGroup::query()->count())->toBe(2);

    $result = mcpCall($workspace, $key, 'arrange_status_page', [...$remove, 'confirm' => 'web-1']);
    expect($result['data']['removedGroups'])->toBe(['Cache'])
        ->and(ServiceGroup::query()->pluck('name')->all())->toBe(['Web'])
        ->and($web->fresh()->services)->toBe(['docker:aaa']);
    Storage::disk('public')->assertMissing('service-icons/cache.png');
    Storage::disk('public')->assertExists('service-icons/old.png');
});

test('arrange_status_page chooses monitors, which always lead the page', function () {
    [$workspace, $machine, $key] = statusToolsSetup();
    $shop = statusToolsMonitor($workspace, $machine, ['name' => 'Shop', 'url' => 'https://shop.example.com/']);
    $api = statusToolsMonitor($workspace, $machine, ['name' => 'API', 'url' => 'https://api.example.com/']);

    expect(mcpCall($workspace, $key, 'arrange_status_page', ['machine' => 'web-1', 'sections' => [['name' => 'Web', 'services' => ['monitor:'.$shop->id]]]])['text'])
        ->toContain('Monitors can’t go in a section');

    $result = mcpCall($workspace, $key, 'arrange_status_page', [
        'machine' => 'web-1',
        'sections' => [['name' => 'App', 'services' => ['docker:aaa']]],
        'monitors' => ['Shop'],
        'labels' => [['service' => 'monitor:'.$shop->id, 'label' => 'Storefront']],
        'hide_unlisted' => true,
    ]);

    expect($result['error'])->toBeFalse()
        ->and(statusToolsSections($result['data']['preview']))->toBe([['Websites', ['Storefront']], ['App', ['Umami']]])
        ->and($machine->fresh()->statusPage->services['monitor:'.$api->id])->toBe(['shown' => false, 'label' => null]);
});

test('arrange_status_page refuses unclear arrangements before changing anything', function () {
    [$workspace, $machine, $key] = statusToolsSetup();
    $call = fn (array $args) => mcpCall($workspace, $key, 'arrange_status_page', ['machine' => 'web-1', ...$args]);

    expect($call(['sections' => [['name' => 'A', 'services' => ['docker:aaa']], ['name' => 'B', 'services' => ['serveros-umami-umami-1']]]])['text'])->toContain('is in two sections')
        ->and($call(['sections' => [['name' => 'Web', 'services' => []], ['name' => 'web', 'services' => []]]])['text'])->toContain('Two sections are called')
        ->and($call(['sections' => [['name' => '  ', 'services' => []]]])['text'])->toContain('sections.0.name field is required')
        ->and($call(['sections' => [['name' => str_repeat('x', 61), 'services' => []]]])['text'])->toContain('60')
        ->and($call(['sections' => [['name' => 'A', 'services' => ['nope']]]])['text'])->toContain('No service “nope”')
        ->and($call(['sections' => [], 'labels' => [['service' => 'docker:aaa', 'label' => str_repeat('x', 61)]]])['text'])->toContain('60')
        ->and($call(['sections' => [], 'monitors' => ['monitor:404']])['text'])->toContain('No monitor')
        ->and($call(['sections' => [['name' => 'A', 'services' => [], 'icon_url' => 'http://example.com/a.png']]])['text'])->toContain('icon')
        ->and($call([])['error'])->toBeTrue()
        ->and(ServiceGroup::query()->count())->toBe(0)
        ->and(StatusPage::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
});

test('arrange_status_page sets section icons from the web, safely', function () {
    Storage::fake('public');
    app()->instance(HostResolver::class, new class extends HostResolver
    {
        public function resolve(string $host): array
        {
            return str_contains($host, 'internal') ? [] : ['93.184.215.14'];
        }
    });
    Http::fake([
        'https://cdn.example.com/web.png' => Http::response(base64_decode(STATUS_TOOLS_PNG), 200, ['Content-Type' => 'image/png']),
        'https://cdn.example.com/evil.svg' => Http::response('<svg xmlns="http://www.w3.org/2000/svg"></svg>', 200, ['Content-Type' => 'image/svg+xml']),
    ]);
    [$workspace, $machine, $key] = statusToolsSetup();

    $result = mcpCall($workspace, $key, 'arrange_status_page', ['machine' => 'web-1', 'sections' => [
        ['name' => 'Web', 'services' => ['docker:aaa'], 'icon_url' => 'https://cdn.example.com/web.png'],
    ]]);
    $group = ServiceGroup::query()->sole();
    expect($result['error'])->toBeFalse()
        ->and($group->icon_path)->toStartWith("service-icons/{$machine->id}/")
        ->and($result['data']['sections'][0]['iconUrl'])->toBe(Storage::disk('public')->url($group->icon_path));
    Storage::disk('public')->assertExists($group->icon_path);

    // A bad icon changes nothing at all.
    $bad = mcpCall($workspace, $key, 'arrange_status_page', ['machine' => 'web-1', 'sections' => [
        ['name' => 'Web', 'services' => ['docker:aaa', 'redis']],
        ['name' => 'Data', 'services' => ['postgres'], 'icon_url' => 'https://cdn.example.com/evil.svg'],
    ]]);
    expect($bad['text'])->toContain('isn’t a PNG')
        ->and(ServiceGroup::query()->count())->toBe(1)
        ->and($group->fresh()->services)->toBe(['docker:aaa']);
    expect(mcpCall($workspace, $key, 'arrange_status_page', ['machine' => 'web-1', 'sections' => [
        ['name' => 'Web', 'services' => ['docker:aaa'], 'icon_url' => 'https://internal.example.com/web.png'],
    ]])['text'])->toContain('private network');

    // null takes it off.
    $path = $group->icon_path;
    mcpCall($workspace, $key, 'arrange_status_page', ['machine' => 'web-1', 'sections' => [['name' => 'Web', 'services' => ['docker:aaa'], 'icon_url' => null]]]);
    expect($group->fresh()->icon_path)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

test('arrange_status_page takes deployed apps out of groups the dashboard filed them in', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $commit = str_repeat('b', 40);
    $machine = statusToolsMachine($workspace, 'web-1', [
        statusToolsService('docker:old', 'serveros-shop-'.str_repeat('a', 12), 'exited', ['managed' => 'true']),
        statusToolsService('docker:new', 'serveros-shop-'.substr($commit, 0, 12), 'running', ['managed' => 'true']),
        statusToolsService('docker:ccc', 'postgres'),
    ]);
    DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'deploy', 'service' => 'shop', 'state' => 'succeeded', 'log' => [],
        'command' => ['job' => ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => $commit]],
    ]);
    $old = ServiceGroup::put($machine, new ServiceGroup, 'Apps', ['app:shop', 'docker:ccc']);
    $key = mcpKey($workspace);

    $services = collect(mcpCall($workspace, $key, 'get_status_page', ['machine' => 'web-1'])['data']['services'])->keyBy('key');
    expect($services['docker:old'])->toMatchArray(['retired' => true, 'app' => 'shop'])
        ->and($services['docker:new'])->toMatchArray(['retired' => false, 'app' => 'shop', 'panelName' => 'shop', 'suggested' => 'Shop']);

    $result = mcpCall($workspace, $key, 'arrange_status_page', ['machine' => 'web-1', 'sections' => [['name' => 'Shop', 'services' => ['app:shop']]], 'hide_unlisted' => true]);

    expect($result['error'])->toBeFalse()
        ->and($old->fresh()->services)->toBe(['docker:ccc'])
        ->and(ServiceGroup::query()->where('name', 'Shop')->sole()->services)->toBe(['docker:new'])
        // The retired version is hidden; postgres is in no section shown... hidden too.
        ->and(statusToolsSections($result['data']['preview']))->toBe([['Shop', ['Shop']]]);
});

test('arrange_status_page works on a plan without status pages, but says the page can’t go live', function () {
    [$workspace, $machine, $key] = statusToolsSetup(Plan::Essential);

    $result = mcpCall($workspace, $key, 'arrange_status_page', ['machine' => 'web-1', 'sections' => [['name' => 'Web', 'services' => ['docker:aaa']]]]);
    expect($result['error'])->toBeFalse()
        ->and(collect($result['data']['notes'])->implode(' '))->toContain('can’t be published on the current plan')
        ->and(ServiceGroup::query()->count())->toBe(1);

    // One that was switched on before the plan dropped can't be saved, as in the dashboard.
    $machine->statusPage()->update(['enabled' => true]);
    expect(mcpCall($workspace, $key, 'arrange_status_page', ['machine' => 'web-1', 'sections' => [['name' => 'Data', 'services' => ['postgres']]]])['text'])->toContain('Status pages come with Pro')
        ->and(ServiceGroup::query()->count())->toBe(1);
});

test('the fleet page is read and changed like its settings page', function () {
    [$workspace, $web, $key] = statusToolsSetup(Plan::Business);
    $db = statusToolsMachine($workspace, 'db-1', [statusToolsService('docker:pg', 'postgres')]);
    statusToolsPage($web, ['enabled' => false, 'services' => ['docker:bbb' => ['shown' => false, 'label' => null]]]);
    ServiceGroup::put($web, new ServiceGroup, 'Web', ['docker:aaa']);

    $data = mcpCall($workspace, statusToolsViewer($workspace), 'get_fleet_status_page')['data'];
    expect($data['planAllows'])->toBeTrue()
        ->and($data['page'])->toMatchArray(['exists' => false, 'slug' => 'acme', 'everyMachine' => true, 'machines' => null])
        ->and(collect($data['machines'])->pluck('hasOwnSettings', 'name')->all())->toBe(['db-1' => false, 'web-1' => true])
        ->and(collect($data['preview']['sections'])->pluck('name')->all())->toBe(['db-1', 'web-1']);

    $result = mcpCall($workspace, $key, 'update_fleet_status_page', ['enabled' => true, 'title' => 'Acme status']);
    $page = $workspace->fleetStatusPage()->sole();
    expect($result['error'])->toBeFalse()
        ->and($result['data']['created'])->toBeTrue()
        ->and($page->enabled)->toBeTrue()
        ->and($page->title)->toBe('Acme status')
        ->and($page->machines)->toBeNull()
        ->and($result['data']['page']['live'])->toBeTrue()
        // db-1 has no page of its own: it shows as itself, not as all it runs.
        ->and(statusToolsSections($result['data']['preview']))->toBe([['db-1', ['Server']], ['web-1', ['Umami (Web)', 'Nginx', 'Postgres', 'Redis']]]);
    $activity = Activity::query()->where('action', 'status_page.fleet')->sole();
    expect($activity->subject)->toBe('Acme status')
        ->and($activity->meta)->toMatchArray(['enabled' => true, 'via' => 'mcp']);

    // From every machine, taking one off leaves a list of the rest.
    mcpCall($workspace, $key, 'update_fleet_status_page', ['remove_machines' => ['web-1']]);
    expect($page->fresh()->machines)->toBe([$db->id]);
    mcpCall($workspace, $key, 'update_fleet_status_page', ['add_machines' => [(string) $web->id]]);
    expect($page->fresh()->machines)->toBe([$db->id, $web->id]);
    mcpCall($workspace, $key, 'update_fleet_status_page', ['machines' => ['web-1']]);
    expect($page->fresh()->machines)->toBe([$web->id]);
    mcpCall($workspace, $key, 'update_fleet_status_page', ['every_machine' => true]);
    expect($page->fresh()->machines)->toBeNull();

    $same = mcpCall($workspace, $key, 'update_fleet_status_page', ['every_machine' => true, 'title' => 'Acme status']);
    expect($same['data']['changed'])->toBe([])
        ->and(Activity::query()->where('action', 'status_page.fleet')->count())->toBe(5);

    // Clearing.
    mcpCall($workspace, $key, 'update_fleet_status_page', ['title' => '', 'notice' => 'All good', 'notice_kind' => 'maintenance']);
    expect($page->fresh()->title)->toBeNull()
        ->and($page->fresh()->notice_kind)->toBe('maintenance');
});

test('update_fleet_status_page checks what it’s given', function () {
    [$workspace, $web, $key] = statusToolsSetup(Plan::Business);
    [, $other] = statusToolsSetup(Plan::Pro, 'Other');
    statusToolsPage($other, ['slug' => 'taken']);
    $call = fn (array $args) => mcpCall($workspace, $key, 'update_fleet_status_page', $args);

    expect($call(['slug' => 'taken'])['text'])->toContain('That address is taken.')
        ->and($call(['slug' => 'Bad Slug'])['text'])->toContain('Use lowercase letters')
        ->and($call(['machines' => [(string) $other->id]])['text'])->toContain('No machine')
        ->and($call(['every_machine' => true, 'machines' => ['web-1']])['text'])->toContain('every_machine covers every machine')
        ->and($call(['notice_kind' => 'party', 'notice' => 'x'])['error'])->toBeTrue()
        ->and($call([])['text'])->toContain('Nothing to change')
        ->and(WorkspaceStatusPage::query()->count())->toBe(0);

    expect(mcpCall($workspace, statusToolsViewer($workspace), 'update_fleet_status_page', ['enabled' => false])['text'])->toContain('doesn’t allow that');

    // A machine page can't take the fleet page's address either.
    $call(['slug' => 'acme-fleet']);
    expect(mcpCall($workspace, $key, 'update_status_page', ['machine' => 'web-1', 'slug' => 'acme-fleet'])['text'])->toContain('That address is taken.');
});

test('the fleet page is Business only', function () {
    [$workspace, , $key] = statusToolsSetup(Plan::Pro);

    expect(mcpCall($workspace, $key, 'get_fleet_status_page')['data'])->toMatchArray(['planAllows' => false])
        ->and(mcpCall($workspace, $key, 'get_fleet_status_page')['data']['note'])->toContain('comes with Business')
        ->and(mcpCall($workspace, $key, 'update_fleet_status_page', ['enabled' => true])['text'])->toContain('One status page for every machine comes with Business.')
        ->and(WorkspaceStatusPage::query()->count())->toBe(0);
});

test('preview_status_page shows what visitors see and who they are', function () {
    [$workspace, $machine, $key] = statusToolsSetup();
    $preview = fn (array $args) => mcpCall($workspace, $key, 'preview_status_page', $args)['data'];

    expect($preview(['machine' => 'web-1']))->toMatchArray(['badge' => null])
        ->and($preview(['machine' => 'web-1'])['visitors'])->toContain('there’s no page yet')
        ->and($preview(['machine' => 'web-1'])['page'])->toMatchArray(['kind' => 'machine', 'exists' => false, 'live' => false]);

    $page = statusToolsPage($machine, ['title' => 'Acme']);
    $data = $preview(['machine' => 'web-1']);
    expect($data['visitors'])->toBe('Anyone with the link.')
        ->and($data['badge'])->toBe('partial outage')
        ->and($data['page'])->toMatchArray(['exists' => true, 'live' => true, 'url' => rtrim((string) config('app.url'), '/').'/status/web-1'])
        ->and($data['report']['title'])->toBe('Acme')
        ->and($data['report']['overall'])->toBe(['status' => 'down', 'label' => 'Partial outage'])
        ->and($data['report'])->not->toHaveKey('days')
        ->and($data['report']['sections'][0]['components'][0])->not->toHaveKey('uptimeDays');

    $page->update(['public' => false]);
    expect($preview(['machine' => 'web-1'])['visitors'])->toContain('Only members of Acme')
        ->and($preview(['machine' => 'web-1'])['badge'])->toBeNull();

    $page->update(['enabled' => false]);
    expect($preview(['machine' => 'web-1'])['visitors'])->toContain('switched off');

    $page->update(['enabled' => true]);
    $workspace->forceFill(['plan' => Plan::Essential])->save();
    expect($preview(['machine' => 'web-1'])['visitors'])->toContain('status pages need Pro or Business');
    $workspace->forceFill(['plan' => Plan::Pro])->save();

    $days = $preview(['machine' => 'web-1', 'include_days' => true])['report'];
    expect($days['days'])->toHaveCount(90)
        ->and($days['sections'][0]['components'][0]['uptimeDays'])->toHaveCount(90);

    expect(mcpCall($workspace, $key, 'preview_status_page', [])['text'])->toContain('Give either machine')
        ->and(mcpCall($workspace, $key, 'preview_status_page', ['machine' => 'web-1', 'fleet' => true])['text'])->toContain('Give either machine');
});

test('preview_status_page previews the fleet page and folds long pages', function () {
    [$workspace, $web, $key] = statusToolsSetup(Plan::Business);
    statusToolsMachine($workspace, 'db-1');
    statusToolsMachine($workspace, 'cache-1');
    // Each machine's page shows everything it runs.
    foreach (Machine::query()->get() as $machine) {
        statusToolsPage($machine, ['enabled' => false]);
    }
    $fleet = new WorkspaceStatusPage(['slug' => 'acme', 'enabled' => true, 'public' => true, 'show_uptime' => true]);
    $fleet->workspace_id = $workspace->id;
    $fleet->save();

    $data = mcpCall($workspace, $key, 'preview_status_page', ['fleet' => true])['data'];
    expect($data['page'])->toMatchArray(['kind' => 'fleet', 'live' => true, 'slug' => 'acme'])
        ->and($data['visitors'])->toBe('Anyone with the link.')
        ->and($data['badge'])->toBe('partial outage')
        ->and($data['report']['sectionsFold'])->toBeTrue()
        // Each machine has nginx down, so every section stays open.
        ->and(collect($data['report']['sections'])->pluck('startsFolded')->all())->toBe([false, false, false]);

    // With nothing wrong, sections start folded.
    foreach (Machine::query()->get() as $machine) {
        $inventory = $machine->inventory;
        $inventory['services'] = array_map(fn ($s) => [...$s, 'status' => 'running'], $inventory['services']);
        $machine->forceFill(['inventory' => $inventory])->save();
    }
    $data = mcpCall($workspace, $key, 'preview_status_page', ['fleet' => true])['data'];
    expect($data['badge'])->toBe('operational')
        ->and(collect($data['report']['sections'])->pluck('startsFolded')->all())->toBe([true, true, true]);
});

test('a fleet page covering one machine shows that machine’s own sections, not the machine', function () {
    [$workspace, $web, $key] = statusToolsSetup(Plan::Business);
    $monitor = statusToolsMonitor($workspace, $web, ['name' => 'Website', 'url' => 'https://acme.test']);
    mcpCall($workspace, $key, 'arrange_status_page', [
        'machine' => $web->id,
        'sections' => [['name' => 'Platform', 'services' => ['docker:ccc', 'docker:ddd']]],
        'labels' => [['service' => 'docker:ccc', 'label' => 'Database'], ['service' => 'docker:ddd', 'label' => 'Cache']],
        'monitors' => ['monitor:Website'],
        'hide_unlisted' => true,
    ]);
    $fleet = new WorkspaceStatusPage(['slug' => 'acme', 'enabled' => true, 'public' => true, 'show_uptime' => true]);
    $fleet->workspace_id = $workspace->id;
    $fleet->save();

    $report = mcpCall($workspace, $key, 'preview_status_page', ['fleet' => true])['data']['report'];
    expect(statusToolsSections($report))->toBe([['Websites', ['Website']], ['Platform', ['Database', 'Cache']]])
        ->and($report['sections'][0]['components'][0]['key'])->toBe("{$web->id}:monitor:{$monitor->id}");

    // Two machines: a section each, named after the machine, by name.
    statusToolsMachine($workspace, 'db-1');
    $report = mcpCall($workspace, $key, 'preview_status_page', ['fleet' => true])['data']['report'];
    expect(array_column($report['sections'], 'name'))->toBe(['db-1', 'web-1'])
        ->and($report['sections'][1]['components'][0]['label'])->toBe('Website (Websites)');
});

test('the fleet page can show uptime monitors that aren’t on a machine', function () {
    [$workspace, , $key] = statusToolsSetup(Plan::Business);
    $site = $workspace->monitors()->create(['name' => 'Website', 'type' => 'http', 'url' => 'https://www.example.com/', 'interval_seconds' => 60, 'timeout_seconds' => 10, 'status' => 'up']);

    expect(mcpCall($workspace, $key, 'get_fleet_status_page', [])['data']['monitors'])
        ->toBe([['id' => $site->id, 'name' => 'Website', 'paused' => false, 'onPage' => false]]);

    $result = mcpCall($workspace, $key, 'update_fleet_status_page', ['enabled' => true, 'monitors' => ['Website']]);
    expect($result['error'])->toBeFalse()
        ->and($workspace->fresh()->fleetStatusPage->monitors)->toBe([$site->id])
        ->and($result['data']['page']['monitors'])->toBe([$site->id]);

    // A machine's monitors come with the machine.
    $onMachine = $workspace->monitors()->create(['machine_id' => $workspace->machines()->first()->id, 'name' => 'API', 'type' => 'http', 'url' => 'https://api.example.com/', 'interval_seconds' => 60, 'timeout_seconds' => 10]);
    expect(mcpCall($workspace, $key, 'update_fleet_status_page', ['monitors' => [$onMachine->id]])['text'])->toContain('aren’t on a machine');

    mcpCall($workspace, $key, 'update_fleet_status_page', ['monitors' => []]);
    expect($workspace->fresh()->fleetStatusPage->monitors)->toBeNull();
});

test('incidents are posted, listed, updated and resolved over MCP', function () {
    [$workspace, , $key] = statusToolsSetup();

    $posted = mcpCall($workspace, $key, 'post_status_incident', ['title' => 'Checkout is failing', 'impact' => 'major', 'state' => 'investigating', 'message' => 'Looking into it.', 'machine' => 'web-1']);
    expect($posted['error'])->toBeFalse()
        ->and($posted['data']['incident'])->toMatchArray(['title' => 'Checkout is failing', 'machine' => 'web-1', 'resolvedAt' => null])
        ->and(Activity::query()->where('action', 'status_page.incident_open')->sole()->meta)->toMatchArray(['via' => 'mcp']);
    $id = $posted['data']['incident']['id'];

    expect(mcpCall($workspace, $key, 'list_status_incidents', ['open' => true])['data']['incidents'])->toHaveCount(1);

    $resolved = mcpCall($workspace, $key, 'update_status_incident', ['incident' => $id, 'state' => 'resolved', 'message' => 'Fixed.']);
    expect($resolved['data']['incident']['state'])->toBe('resolved')
        ->and($resolved['data']['incident']['updates'])->toHaveCount(2)
        ->and(mcpCall($workspace, $key, 'list_status_incidents', ['open' => true])['data']['incidents'])->toBe([]);

    expect(mcpCall($workspace, $key, 'post_status_incident', ['title' => 'X', 'impact' => 'catastrophic', 'state' => 'investigating', 'message' => 'x'])['text'])->toContain('Invalid arguments')
        ->and(mcpCall($workspace, $key, 'update_status_incident', ['incident' => 999999, 'state' => 'resolved', 'message' => 'x'])['text'])->toContain('No incident')
        ->and(mcpCall($workspace, statusToolsViewer($workspace), 'post_status_incident', ['title' => 'X', 'impact' => 'major', 'state' => 'investigating', 'message' => 'x'])['text'])->toContain('doesn’t allow that');
});

test('AI apps can show a service only during problems, and arrange the fleet page', function () {
    [$workspace, $web, $key] = statusToolsSetup(Plan::Business);
    statusToolsMachine($workspace, 'db-1', [statusToolsService('docker:pg', 'postgres')]);

    $result = mcpCall($workspace, $key, 'update_status_page', ['machine' => 'web-1', 'services' => [
        ['service' => 'docker:aaa', 'only_during_problems' => true],
    ]]);
    expect($result['error'])->toBeFalse()
        ->and($web->fresh()->statusPage->services['docker:aaa'])->toMatchArray(['shown' => true, 'quiet' => true]);

    mcpCall($workspace, $key, 'update_fleet_status_page', ['new_machines' => 'services']);
    $components = mcpCall($workspace, $key, 'get_fleet_status_page')['data']['components'];
    $pg = collect($components)->firstWhere('machine', 'db-1')['key'];

    $result = mcpCall($workspace, $key, 'update_fleet_status_page', [
        'layout' => ['sections' => [['name' => 'Data', 'components' => [$pg]]], 'headings' => [(string) $web->id => 'Web']],
    ]);
    $page = $workspace->fleetStatusPage()->sole();
    expect($result['error'])->toBeFalse()
        ->and($page->new_machines)->toBe('services')
        ->and($page->layout['sections'][0]['components'])->toBe([$pg])
        ->and($page->layout['headings'])->toBe([(string) $web->id => 'Web'])
        ->and(collect($result['data']['preview']['sections'])->pluck('name')->first())->toBe('Data');

    expect(mcpCall($workspace, $key, 'update_fleet_status_page', ['machine_groups' => ['Nope']])['text'])->toContain('No machine group');
});

test('a monitor can have a section of its own, kept when the page is saved from the dashboard', function () {
    [$workspace, $machine, $key] = statusToolsSetup();
    $shop = statusToolsMonitor($workspace, $machine, ['name' => 'Shop', 'url' => 'https://shop.example.com/']);
    $uk = statusToolsMonitor($workspace, $machine, ['name' => 'UK', 'type' => 'tcp', 'host' => '203.0.113.10', 'port' => 443]);
    statusToolsPage($machine);

    $result = mcpCall($workspace, $key, 'update_status_page', ['machine' => 'web-1', 'services' => [
        ['service' => 'monitor:'.$uk->id, 'section' => 'Cloud (UK)', 'label' => 'Cloud servers'],
    ]]);
    expect($result['error'])->toBeFalse();
    $sections = statusToolsSections($result['data']['preview']);
    // The other monitors lead; the sectioned one follows in its own section, before the services.
    expect($sections[0])->toBe(['Websites', ['Shop']])
        ->and($sections[1])->toBe(['Cloud (UK)', ['Cloud servers']])
        ->and($machine->fresh()->statusPage->services['monitor:'.$uk->id]['section'])->toBe('Cloud (UK)');

    // Services can't take one here.
    expect(mcpCall($workspace, $key, 'update_status_page', ['machine' => 'web-1', 'services' => [['service' => 'docker:aaa', 'section' => 'X']]])['text'])
        ->toContain('Only monitors');

    // Saved from the dashboard, which doesn't send sections: it stays.
    $page = $machine->fresh()->statusPage;
    $this->actingAs($workspace->owner)->put('https://'.$workspace->host().'/machines/'.$machine->id.'/status-page', [
        'enabled' => true, 'public' => true, 'slug' => $page->slug, 'include_new' => true, 'show_uptime' => true,
        'services' => [['key' => 'monitor:'.$uk->id, 'shown' => true, 'label' => 'Renamed'], ['key' => 'monitor:'.$shop->id, 'shown' => true, 'label' => null]],
    ])->assertSessionHasNoErrors();
    expect($page->fresh()->services['monitor:'.$uk->id]['label'])->toBe('Renamed');
    expect($page->fresh()->services['monitor:'.$uk->id]['section'] ?? null)->toBe('Cloud (UK)');

    // Empty puts it back with the others.
    mcpCall($workspace, $key, 'update_status_page', ['machine' => 'web-1', 'services' => [['service' => 'monitor:'.$uk->id, 'section' => '']]]);
    expect(statusToolsSections(mcpCall($workspace, $key, 'preview_status_page', ['machine' => 'web-1'])['data']['report'])[0])
        ->toBe(['Endpoints', ['Shop', 'Renamed']]);
});
