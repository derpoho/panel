<?php

use App\Enums\Plan;
use App\Models\Machine;
use App\Models\MachineGroup;
use App\Models\ServiceGroup;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceStatusPage;
use App\Support\StatusPages\FleetStatusReport;
use App\Support\StatusPages\StatusDiscordEmbed;
use App\Support\StatusPages\StatusReport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * An online machine with a small inventory, in a workspace on the plan.
 *
 * @return array{0: Workspace, 1: Machine}
 */
function statusMachine(?Plan $plan = Plan::Pro): array
{
    $factory = Workspace::factory();
    $workspace = ($plan ? $factory->onPlan($plan) : $factory->trialExpired())->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'Web One']);
    $service = fn ($key, $name, $status) => ['key' => $key, 'name' => $name, 'kind' => 'app', 'manager' => 'docker', 'status' => $status, 'ports' => [], 'confidence' => 90];
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
            $service('docker:aaa', 'serveros-umami-umami-1', 'running'),
            $service('docker:bbb', 'serveros-umami-db-1', 'running'),
            $service('systemd:nginx.service', 'nginx.service', 'failed'),
        ]],
    ])->save();

    return [$workspace, $machine];
}

function statusSettings(Workspace $workspace, Machine $machine): string
{
    return 'https://'.$workspace->host().'/machines/'.$machine->id;
}

/** @param  array<string, mixed>  $overrides */
function statusPayload(array $overrides = []): array
{
    return [
        'enabled' => true, 'public' => true, 'slug' => 'web-one', 'title' => 'Acme status', 'description' => null,
        'include_new' => true, 'show_uptime' => true, 'notice' => null, 'notice_kind' => null,
        'services' => [
            ['key' => 'docker:aaa', 'shown' => true, 'label' => 'Analytics'],
            ['key' => 'docker:bbb', 'shown' => false, 'label' => null],
            ['key' => 'systemd:nginx.service', 'shown' => true, 'label' => ''],
        ],
        ...$overrides,
    ];
}

test('the machine can be renamed and moved to another environment', function () {
    [$workspace, $machine] = statusMachine();

    $this->actingAs($workspace->owner)
        ->patch(statusSettings($workspace, $machine), ['name' => '  Web Prod  ', 'environment' => 'staging'])
        ->assertRedirect();

    expect($machine->fresh()->name)->toBe('Web Prod')
        ->and($machine->fresh()->environment->value)->toBe('staging');
});

test('the settings page offers a free address and every service', function () {
    [$workspace, $machine] = statusMachine();

    $this->actingAs($workspace->owner)->get(statusSettings($workspace, $machine).'/settings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/MachineSettings')
            ->where('planAllows', true)
            ->where('statusPage.exists', false)
            ->where('statusPage.slug', 'web-one')
            ->has('statusPage.services', 3)
            ->where('statusPage.services.0.suggested', 'Umami')
            ->where('statusPage.services.2.suggested', 'Nginx'));
});

test('numbered nodes keep their number when it is all that tells them apart', function () {
    expect(StatusReport::friendlyNames([
        'docker:n1' => 'eu-west-node-1',
        'docker:n2' => 'eu-west-node-2',
        'docker:umami' => 'serveros-umami-umami-1',
        'systemd:nginx.service' => 'nginx.service',
    ]))->toBe([
        'docker:n1' => 'Eu West Node 1',
        'docker:n2' => 'Eu West Node 2',
        'docker:umami' => 'Umami',
        'systemd:nginx.service' => 'Nginx',
    ]);

    [$workspace, $machine] = statusMachine();
    $inventory = $machine->inventory;
    $inventory['services'][] = ['key' => 'docker:ccc', 'name' => 'serveros-umami-umami-2', 'kind' => 'app', 'manager' => 'docker', 'status' => 'running', 'ports' => [], 'confidence' => 90];
    $machine->forceFill(['inventory' => $inventory])->save();
    $page = StatusPage::query()->forceCreate(['machine_id' => $machine->id, 'slug' => 'web-one', 'enabled' => true, 'public' => true, 'include_new' => true, 'show_uptime' => false]);

    $labels = array_column((new StatusReport($page->fresh()))->services(), 'label', 'key');
    expect($labels['docker:aaa'])->toBe('Umami 1')
        ->and($labels['docker:ccc'])->toBe('Umami 2')
        ->and($labels['systemd:nginx.service'])->toBe('Nginx');

    $this->actingAs($workspace->owner)->get(statusSettings($workspace, $machine).'/settings')
        ->assertInertia(fn (Assert $page) => $page
            ->where('statusPage.services.0.suggested', 'Umami 1')
            ->where('statusPage.services.3.suggested', 'Umami 2'));
});

test('a status page lists the chosen services, labelled, with live status', function () {
    [$workspace, $machine] = statusMachine();
    ServiceGroup::query()->forceCreate(['machine_id' => $machine->id, 'name' => 'Web', 'services' => ['docker:aaa']]);

    $this->actingAs($workspace->owner)->put(statusSettings($workspace, $machine).'/status-page', statusPayload())
        ->assertRedirect()->assertSessionHasNoErrors();

    // Anyone can open a public page.
    auth()->logout();
    $this->get('/status/web-one')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('status/Show')
            ->where('status.title', 'Acme status')
            ->where('status.overall.label', 'Partial outage')
            ->where('status.sections.0.name', 'Web')
            ->where('status.sections.0.components.0.label', 'Analytics')
            ->where('status.sections.0.components.0.status', 'operational')
            ->where('status.sections.1.name', null)
            ->where('status.sections.1.components.0.label', 'Nginx')
            ->where('status.sections.1.components.0.status', 'down')
            ->has('status.days', 90));

    $this->getJson('/status/web-one/status.json')
        ->assertOk()
        ->assertJsonPath('status.status', 'down')
        ->assertJsonCount(2, 'components');

    $this->get('/status/web-one/badge.svg')
        ->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml')
        ->assertSee('partial outage', false);

    // As a widget other sites can frame, in the look they ask for.
    $this->get('/status/web-one/embed?view=bar&theme=dark')
        ->assertOk()
        ->assertHeader('Content-Security-Policy', 'frame-ancestors *')
        ->assertHeaderMissing('X-Frame-Options')
        ->assertInertia(fn (Assert $page) => $page
            ->component('status/Embed')
            ->where('view', 'bar')
            ->where('theme', 'dark')
            ->where('pageUrl', route('status.show', 'web-one'))
            ->where('status.overall.label', 'Partial outage'));
    $this->get('/status/web-one/embed?view=nonsense&theme=pink')
        ->assertInertia(fn (Assert $page) => $page->where('view', 'card')->where('theme', 'light'));
});

test('private pages are for the workspace only', function () {
    [$workspace, $machine] = statusMachine();
    $this->actingAs($workspace->owner)->put(statusSettings($workspace, $machine).'/status-page', statusPayload(['public' => false]));
    auth()->logout();

    $this->get('/status/web-one')->assertRedirect(route('login'));
    $this->get('/status/web-one/badge.svg')->assertNotFound();
    // A private page can't be embedded, even for a member.
    $this->actingAs($workspace->owner)->get('/status/web-one/embed')->assertNotFound();
    $this->actingAs(User::factory()->create())->get('/status/web-one')->assertNotFound();
    $this->actingAs($workspace->owner)->get('/status/web-one')->assertOk();
});

test('status pages are a Pro feature', function () {
    [$workspace, $machine] = statusMachine(Plan::Essential);

    $this->actingAs($workspace->owner)->put(statusSettings($workspace, $machine).'/status-page', statusPayload())
        ->assertSessionHasErrors('enabled');
    expect(StatusPage::query()->count())->toBe(0);

    // A page saved on Pro goes dark when the plan drops.
    [$pro, $proMachine] = statusMachine();
    $this->actingAs($pro->owner)->put(statusSettings($pro, $proMachine).'/status-page', statusPayload(['slug' => 'pro-page']));
    $this->get('/status/pro-page')->assertOk();
    $pro->forceFill(['plan' => Plan::Essential])->save();
    $this->get('/status/pro-page')->assertNotFound();
});

test('addresses are unique and must be tidy', function () {
    [$workspace, $machine] = statusMachine();
    [, $other] = statusMachine();
    StatusPage::query()->forceCreate(['machine_id' => $other->id, 'slug' => 'taken', 'include_new' => true]);

    $this->actingAs($workspace->owner)->put(statusSettings($workspace, $machine).'/status-page', statusPayload(['slug' => 'taken']))
        ->assertSessionHasErrors('slug');
    $this->actingAs($workspace->owner)->put(statusSettings($workspace, $machine).'/status-page', statusPayload(['slug' => 'Not OK!']))
        ->assertSessionHasErrors('slug');
});

test('uptime counts the snapshots each service was running in', function () {
    [, $machine] = statusMachine();
    $page = StatusPage::query()->forceCreate(['machine_id' => $machine->id, 'slug' => 'up', 'enabled' => true, 'include_new' => true]);
    $yesterday = now()->subDay();

    // Yesterday: four snapshots, aaa in all, bbb from the third on.
    $page->recordSnapshot(['docker:aaa'], $yesterday);
    $page->recordSnapshot(['docker:aaa'], $yesterday);
    $page->recordSnapshot(['docker:aaa', 'docker:bbb'], $yesterday);
    $page->recordSnapshot(['docker:aaa', 'docker:bbb'], $yesterday);
    // Today: aaa down for two snapshots in a row, out of four.
    $page->recordSnapshot(['docker:aaa', 'docker:bbb']);
    $page->recordSnapshot(['docker:bbb']);
    $page->recordSnapshot(['docker:bbb']);
    $page->recordSnapshot(['docker:aaa', 'docker:bbb']);

    $components = collect((new StatusReport($page->fresh()))->toArray()['sections'])->flatMap(fn ($s) => $s['components'])->keyBy('key');

    expect(array_slice($components['docker:aaa']['uptime']['days'], -2))->toBe([100.0, 50.0])
        ->and($components['docker:aaa']['uptime']['percent'])->toBe(75.0)
        // bbb counts from when it first appeared.
        ->and(array_slice($components['docker:bbb']['uptime']['days'], -2))->toBe([100.0, 100.0])
        ->and($components['docker:bbb']['uptime']['days'][0])->toBeNull();
});

test('a page that is not turned on is not served', function () {
    [, $machine] = statusMachine();
    StatusPage::query()->forceCreate(['machine_id' => $machine->id, 'slug' => 'off', 'enabled' => false, 'include_new' => true]);

    $this->get('/status/off')->assertNotFound();
});

test('monitors on the machine lead the page, with their own uptime and incidents', function () {
    [$workspace, $machine] = statusMachine();
    $make = fn (array $attrs) => $workspace->monitors()->create([
        'machine_id' => $machine->id, 'type' => 'http', 'interval_seconds' => 60, 'timeout_seconds' => 10, 'alerts' => true, ...$attrs,
    ]);
    $shop = $make(['name' => 'Shop', 'url' => 'https://shop.example.com/', 'status' => 'down', 'last_response_ms' => 120]);
    $api = $make(['name' => 'API', 'url' => 'https://api.example.com/', 'status' => 'up', 'last_response_ms' => 87]);
    $make(['name' => 'Old', 'url' => 'https://old.example.com/', 'status' => 'up', 'paused' => true]);
    $hidden = $make(['name' => 'Internal', 'url' => 'https://int.example.com/', 'status' => 'up']);
    $api->recordDay(true, 80);
    $api->recordDay(false, null);
    $api->save();
    $shop->incidents()->create(['started_at' => now()->subMinutes(3), 'cause' => 'Returned 502.']);
    $api->incidents()->create(['started_at' => now()->subDays(2), 'resolved_at' => now()->subDays(2)->addMinutes(5), 'cause' => 'Timed out']);
    $api->incidents()->create(['started_at' => now()->subDays(20), 'resolved_at' => now()->subDays(20)->addMinute(), 'cause' => 'Old one']);

    $this->actingAs($workspace->owner)->get(statusSettings($workspace, $machine).'/settings')
        ->assertInertia(fn (Assert $page) => $page
            ->has('statusPage.monitors', 3)
            ->where('statusPage.monitors.0.key', 'monitor:'.$api->id));

    $this->actingAs($workspace->owner)->put(statusSettings($workspace, $machine).'/status-page', statusPayload([
        'services' => [
            ['key' => 'docker:aaa', 'shown' => true, 'label' => null],
            ['key' => 'docker:bbb', 'shown' => false, 'label' => null],
            ['key' => 'systemd:nginx.service', 'shown' => false, 'label' => null],
            ['key' => 'monitor:'.$shop->id, 'shown' => true, 'label' => 'Storefront'],
            ['key' => 'monitor:'.$hidden->id, 'shown' => false, 'label' => null],
        ],
    ]))->assertSessionHasNoErrors();

    auth()->logout();
    $this->get('/status/web-one')
        ->assertInertia(fn (Assert $page) => $page
            ->where('status.overall.label', 'Partial outage')
            ->where('status.sections.0.name', 'Websites')
            ->where('status.sections.0.components.0.label', 'API')
            ->where('status.sections.0.components.0.responseMs', 87)
            ->where('status.sections.0.components.0.uptime.percent', 50)
            ->where('status.sections.0.components.1.label', 'Storefront')
            ->where('status.sections.0.components.1.status', 'down')
            ->where('status.sections.0.components.1.responseMs', null)
            ->has('status.sections.0.components', 2)
            ->where('status.sections.1.name', 'Services')
            ->has('status.incidents', 2)
            ->where('status.incidents.0.label', 'Storefront')
            ->where('status.incidents.0.resolvedAt', null)
            ->where('status.incidents.1.seconds', 300));

    // Checked from outside: they still speak for the page when the server
    // has stopped reporting.
    $shop->update(['status' => 'up']);
    $machine->forceFill(['last_seen_at' => now()->subHour()])->save();
    $this->getJson('/status/web-one/status.json')
        ->assertJsonPath('status.label', 'All systems operational')
        ->assertJsonPath('components.0.response_ms', 87)
        ->assertJsonPath('incidents.0.name', 'Storefront');
});

/** @param  array<string, mixed>  $overrides */
function fleetPayload(array $overrides = []): array
{
    return [
        'enabled' => true, 'public' => true, 'slug' => 'acme', 'title' => 'Acme status', 'description' => null,
        'notice' => null, 'notice_kind' => null, 'show_uptime' => true, 'machines' => null, ...$overrides,
    ];
}

test('a Business workspace gets one status page with a section for each machine', function () {
    [$workspace, $web] = statusMachine(Plan::Business);
    $db = Machine::factory()->for($workspace)->create(['name' => 'Database']);
    $db->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'inventory' => ['scanned_at' => now()->getTimestamp(), 'services' => [
        ['key' => 'docker:ccc', 'name' => 'postgres', 'kind' => 'database', 'manager' => 'docker', 'status' => 'running', 'ports' => [], 'confidence' => 90],
    ], 'listeners' => []]])->save();

    // Web One's own (switched off) page hides umami's database.
    $this->actingAs($workspace->owner)->put(statusSettings($workspace, $web).'/status-page', statusPayload(['enabled' => false]))->assertSessionHasNoErrors();

    $settings = 'https://'.$workspace->host().'/settings/status-page';
    $this->actingAs($workspace->owner)->get($settings)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('workspace/settings/StatusPage')->where('planAllows', true)->has('machines', 2));
    $this->actingAs($workspace->owner)->put($settings, fleetPayload())->assertSessionHasNoErrors();

    auth()->logout();
    $this->get('/status/acme')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('status/Show')
        ->where('status.title', 'Acme status')
        ->where('status.overall.label', 'Partial outage')
        ->has('status.sections', 2)
        // Database has no page of its own: one row for the server, not all it runs.
        ->where('status.sections.0.name', 'Database')
        ->where('status.sections.0.components.0.label', 'Server')
        ->where('status.sections.1.name', 'Web One')
        ->has('status.sections.1.components', 2)
        ->where('status.sections.1.components.0.label', 'Analytics'));

    $this->getJson('/status/acme/status.json')->assertOk()->assertJsonCount(3, 'components');
    $this->get('/status/acme/badge.svg')->assertOk()->assertSee('partial outage', false);

    // Just one machine.
    $this->actingAs($workspace->owner)->put($settings, fleetPayload(['machines' => [$db->id]]))->assertSessionHasNoErrors();
    auth()->logout();
    $this->get('/status/acme')->assertInertia(fn (Assert $page) => $page
        ->has('status.sections', 1)
        ->where('status.overall.label', 'All systems operational'));
});

test('the fleet page is Business only, and shares addresses with machine pages', function () {
    [$workspace, $machine] = statusMachine(Plan::Pro);
    $settings = 'https://'.$workspace->host().'/settings/status-page';

    $this->actingAs($workspace->owner)->put($settings, fleetPayload())->assertSessionHasErrors('enabled');

    $this->actingAs($workspace->owner)->put(statusSettings($workspace, $machine).'/status-page', statusPayload(['slug' => 'taken']))->assertSessionHasNoErrors();
    $this->actingAs($workspace->owner)->put($settings, fleetPayload(['enabled' => false, 'slug' => 'taken']))->assertSessionHasErrors('slug');
    $this->actingAs($workspace->owner)->put($settings, fleetPayload(['enabled' => false, 'slug' => 'fleet']))->assertSessionHasNoErrors();
    $this->actingAs($workspace->owner)->put(statusSettings($workspace, $machine).'/status-page', statusPayload(['slug' => 'fleet']))->assertSessionHasErrors('slug');

    $this->get('/status/fleet')->assertNotFound();
});

test('machines on a live fleet page have their uptime counted', function () {
    [$workspace, $machine] = statusMachine(Plan::Business);
    expect(StatusPage::recordingFor($machine))->toBeNull();

    $fleet = new WorkspaceStatusPage(['slug' => 'acme', 'enabled' => true, 'public' => true, 'show_uptime' => true]);
    $fleet->workspace_id = $workspace->id;
    $fleet->save();

    $page = StatusPage::recordingFor($machine->fresh());
    expect($page)->not->toBeNull()
        ->and($page->exists)->toBeTrue()
        ->and($page->enabled)->toBeFalse();
});

test('the fleet headline counts services, not machines', function () {
    $machine = fn (string $status, array $components) => ['status' => $status, 'label' => '', 'components' => $components];

    expect(FleetStatusReport::overall([$machine('down', ['operational', 'operational', 'down'])])['label'])->toBe('Partial outage')
        ->and(FleetStatusReport::overall([$machine('down', ['down']), $machine('down', ['down'])])['label'])->toBe('Major outage')
        ->and(FleetStatusReport::overall([$machine('operational', ['operational']), $machine('unknown', [])])['label'])->toBe('Some servers aren’t reporting')
        ->and(FleetStatusReport::overall([$machine('unknown', [])])['status'])->toBe('unknown');
});

test('the monitors page points to the fleet status page', function () {
    [$workspace] = statusMachine(Plan::Business);
    $fleet = new WorkspaceStatusPage(['slug' => 'acme', 'enabled' => true, 'public' => true, 'show_uptime' => true]);
    $fleet->workspace_id = $workspace->id;
    $fleet->save();

    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/monitors')
        ->assertInertia(fn (Assert $page) => $page
            ->where('statusPage.live', true)
            ->where('statusPage.url', rtrim((string) config('app.url'), '/').'/status/acme'));
});

test('a public page has a link preview with a picture of its status; a private one has none', function () {
    [$workspace, $machine] = statusMachine();
    $this->actingAs($workspace->owner)->put(statusSettings($workspace, $machine).'/status-page', statusPayload());
    auth()->logout();

    $html = $this->get('/status/web-one')->assertOk()->getContent();
    expect($html)->toContain('<meta property="og:title" content="Acme status · Partial outage">')
        ->toContain('<meta name="theme-color" content="#e5484d">');
    preg_match('#og:image" content="([^"]+)"#', $html, $image);
    expect($image[1])->toContain('/status/web-one/og.png?v=');

    $this->get(html_entity_decode($image[1]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    $this->actingAs($workspace->owner)->put(statusSettings($workspace, $machine).'/status-page', statusPayload(['public' => false]));
    expect($this->get('/status/web-one')->getContent())->not->toContain('og:title');
    $this->get('/status/web-one/og.png')->assertNotFound();
});

test('a public page gives Discord a component embed with each service; a private one gives none', function () {
    [$workspace, $machine] = statusMachine();
    $this->actingAs($workspace->owner)->put(statusSettings($workspace, $machine).'/status-page', statusPayload());
    auth()->logout();

    $html = $this->get('/status/web-one')->assertOk()->getContent();
    expect(preg_match('#<script id="discord:component-embed" type="application/json">(.+?)</script>#s', $html, $match))->toBe(1)
        ->and(strlen($match[1]))->toBeLessThanOrEqual(3000);

    $container = json_decode($match[1], true)['component'];
    $text = collect($container['components'])->pluck('content')
        ->merge(collect($container['components'])->pluck('components.0.content'))->filter()->implode("\n");
    expect($container['type'])->toBe(17)
        ->and($container['accent_color'])->toBe(hexdec('e5484d'))
        ->and($container['components'][0]['accessory'])->toMatchArray(['type' => 2, 'style' => 5, 'label' => 'Open status page'])
        ->and($text)->toContain('Acme status')->toContain('🔴 **Partial outage**')->toContain('🟢 Analytics');

    $this->actingAs($workspace->owner)->put(statusSettings($workspace, $machine).'/status-page', statusPayload(['public' => false]));
    expect($this->get('/status/web-one')->getContent())->not->toContain('discord:component-embed');
});

test('the Discord embed stays under its size limit however many services there are, and says how many it left out', function () {
    $component = fn (int $i) => ['key' => "docker:{$i}", 'label' => "A long service name number {$i} [beta] *", 'status' => 'operational', 'group' => null, 'uptime' => ['percent' => 99.95]];
    $report = [
        'title' => 'Acme </script> status', 'overall' => ['status' => 'operational', 'label' => 'All systems operational'],
        'notice' => null, 'uptimePercent' => 99.9, 'updatedAt' => now()->toIso8601String(),
        'sections' => [['name' => 'Everything', 'components' => array_map($component, range(1, 120))]],
    ];

    $json = StatusDiscordEmbed::encode(StatusDiscordEmbed::payload($report, 'https://status.acme.dev', '#1fa382'));

    expect(strlen($json))->toBeLessThanOrEqual(3000)
        ->and($json)->not->toContain('</script>')
        ->and($json)->toMatch('/and \d+ more on the page/')
        // Their own names are text, not Discord markdown.
        ->and($json)->toContain('\\\\[beta\\\\] \\\\*');
});

test('a service missing from one snapshot, as in a planned restart, doesn’t lose uptime; missing from two does', function () {
    [, $machine] = statusMachine();
    $page = StatusPage::query()->forceCreate(['machine_id' => $machine->id, 'slug' => 'blip', 'enabled' => true, 'include_new' => true]);

    // aaa: a worker's hourly restart, caught by one snapshot.
    // bbb: really down for two, then back.
    $page->recordSnapshot(['docker:aaa', 'docker:bbb']);
    $page->recordSnapshot(['docker:bbb']);
    $page->recordSnapshot(['docker:aaa']);
    $page->recordSnapshot(['docker:aaa']);
    $page->recordSnapshot(['docker:aaa', 'docker:bbb']);

    $components = collect((new StatusReport($page->fresh()))->toArray()['sections'])->flatMap(fn ($s) => $s['components'])->keyBy('key');

    expect($components['docker:aaa']['uptime']['percent'])->toBe(100.0)
        ->and($components['docker:bbb']['uptime']['percent'])->toBe(60.0);
});

test('a service the last scan caught restarting shows as operational when it has reported running since, unless it keeps crashing', function () {
    [, $machine] = statusMachine();
    $inventory = $machine->inventory;
    $inventory['services'][0]['status'] = 'restarting';
    $machine->forceFill(['inventory' => $inventory])->save();
    $page = StatusPage::query()->forceCreate(['machine_id' => $machine->id, 'slug' => 'worker', 'enabled' => true, 'include_new' => true]);
    $status = fn () => collect((new StatusReport($page->fresh()->load('machine')))->toArray()['sections'])
        ->flatMap(fn ($s) => $s['components'])->keyBy('key')['docker:aaa']['status'];

    // No recent report: the scan's word stands.
    expect($status())->toBe(StatusReport::DEGRADED);

    // Running in the minute-by-minute report since.
    $machine->forceFill(['service_stats' => ['docker:aaa' => ['cpu' => 0.1, 'at' => now()->getTimestamp()]]])->save();
    expect($status())->toBe(StatusReport::OPERATIONAL);

    // A crash loop shows up running between crashes: still degraded.
    $machine->forceFill(['alert_state' => ['services' => ['looping' => ['docker:aaa' => now()->getTimestamp()]]]])->save();
    expect($status())->toBe(StatusReport::DEGRADED);
});

test('a workspace with no machines can show a site hosted elsewhere on its status page', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();
    $make = fn (array $attrs) => $workspace->monitors()->create([
        'type' => 'http', 'interval_seconds' => 60, 'timeout_seconds' => 10, 'alerts' => true, 'status' => 'up', ...$attrs,
    ]);
    $site = $make(['name' => 'Website', 'url' => 'https://www.example.com/', 'last_response_ms' => 140]);
    $make(['name' => 'Staging', 'url' => 'https://staging.example.com/']);
    $site->recordDay(true, 140);
    $site->save();
    $site->incidents()->create(['started_at' => now()->subDay(), 'resolved_at' => now()->subDay()->addMinutes(4), 'cause' => 'Returned 502.']);

    $settings = 'https://'.$workspace->host().'/settings/status-page';
    $this->actingAs($workspace->owner)->get($settings)
        ->assertInertia(fn (Assert $page) => $page->has('machines', 0)->has('monitors', 2)->where('monitors.1.target', 'https://www.example.com/'));
    $this->actingAs($workspace->owner)->put($settings, fleetPayload(['monitors' => [$site->id]]))->assertSessionHasNoErrors();

    auth()->logout();
    $this->get('/status/acme')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('status.overall.label', 'All systems operational')
        ->has('status.sections', 1)
        ->where('status.sections.0.name', null)
        ->has('status.sections.0.components', 1)
        ->where('status.sections.0.components.0.label', 'Website')
        ->where('status.sections.0.components.0.responseMs', 140)
        ->where('status.sections.0.components.0.uptime.percent', 100)
        ->has('status.incidents', 1)
        ->where('status.incidents.0.seconds', 240));

    // Down from outside: the headline follows.
    $site->update(['status' => 'down']);
    $this->getJson('/status/acme/status.json')->assertJsonPath('status.label', 'Major outage');
});

test('only the workspace’s own monitors on no machine can go on its status page', function () {
    [$workspace, $machine] = statusMachine(Plan::Business);
    $onMachine = $workspace->monitors()->create(['machine_id' => $machine->id, 'name' => 'API', 'type' => 'http', 'url' => 'https://api.example.com/', 'interval_seconds' => 60, 'timeout_seconds' => 10]);
    $elsewhere = Workspace::factory()->create()->monitors()->create(['name' => 'Theirs', 'type' => 'http', 'url' => 'https://theirs.example.com/', 'interval_seconds' => 60, 'timeout_seconds' => 10]);
    $settings = 'https://'.$workspace->host().'/settings/status-page';

    $this->actingAs($workspace->owner)->put($settings, fleetPayload(['monitors' => [$onMachine->id]]))->assertSessionHasErrors('monitors.0');
    $this->actingAs($workspace->owner)->put($settings, fleetPayload(['monitors' => [$elsewhere->id]]))->assertSessionHasErrors('monitors.0');
});

test('a new status page starts empty, and machines on the fleet page show as themselves until services are picked', function () {
    [$workspace, $web] = statusMachine(Plan::Business);
    $draft = StatusPage::draftFor($web);
    expect($draft->include_new)->toBeFalse()
        ->and((new StatusReport($draft))->services())->toBe([]);

    // Web One has a page with everything hidden; Database has none at all.
    StatusPage::query()->forceCreate(['machine_id' => $web->id, 'slug' => 'web-one', 'enabled' => false, 'include_new' => false]);
    $db = Machine::factory()->for($workspace)->create(['name' => 'Database']);
    $db->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'inventory' => ['services' => [
        ['key' => 'docker:ccc', 'name' => 'postgres', 'kind' => 'database', 'manager' => 'docker', 'status' => 'running', 'ports' => [], 'confidence' => 90],
    ], 'listeners' => []]])->save();
    $fleet = new WorkspaceStatusPage(['slug' => 'acme', 'enabled' => true, 'public' => true, 'show_uptime' => false]);
    $fleet->workspace_id = $workspace->id;
    $fleet->save();

    $sections = collect((new FleetStatusReport($fleet->fresh()))->toArray()['sections'])
        ->map(fn ($s) => [$s['name'], array_column($s['components'], 'label')])->all();

    // Neither drops off the page, and neither spills everything it runs.
    expect($sections)->toBe([['Database', ['Server']], ['Web One', ['Server']]]);
});

test('services nobody picked that are switched off stay off the page, but a crash still shows', function () {
    [, $machine] = statusMachine();
    $inventory = $machine->inventory;
    $inventory['services'][] = ['key' => 'docker:old', 'name' => 'old-worker', 'kind' => 'app', 'manager' => 'docker', 'status' => 'stopped', 'ports' => [], 'confidence' => 90];
    $inventory['services'][] = ['key' => 'docker:was', 'name' => 'queue', 'kind' => 'app', 'manager' => 'docker', 'status' => 'stopped', 'ports' => [], 'confidence' => 90];
    $inventory['services'][] = ['key' => 'docker:mine', 'name' => 'picked', 'kind' => 'app', 'manager' => 'docker', 'status' => 'stopped', 'ports' => [], 'confidence' => 90];
    $machine->forceFill(['inventory' => $inventory])->save();
    $page = StatusPage::query()->forceCreate(['machine_id' => $machine->id, 'slug' => 'web-one', 'enabled' => true, 'include_new' => true, 'services' => ['docker:mine' => ['shown' => true, 'label' => null]]]);
    // The queue ran while the page was watching, then stopped.
    $page->recordSnapshot(['docker:aaa', 'docker:was']);
    $page->save();

    $statuses = array_column((new StatusReport($page->fresh()))->services(), 'status', 'key');

    expect($statuses)->not->toHaveKey('docker:old')
        ->and($statuses['docker:was'])->toBe(StatusReport::DOWN)
        ->and($statuses['docker:mine'])->toBe(StatusReport::DOWN)
        // nginx crashed (failed): that's an outage, shown as one.
        ->and($statuses['systemd:nginx.service'])->toBe(StatusReport::DOWN);
});

/** A connected machine in the workspace with the given running services. */
function statusFleetMachine(Workspace $workspace, string $name, array $services): Machine
{
    $machine = Machine::factory()->for($workspace)->create(['name' => $name]);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'inventory' => ['services' => array_map(
        fn (string $key, string $service) => ['key' => $key, 'name' => $service, 'kind' => 'app', 'manager' => 'docker', 'status' => 'running', 'ports' => [], 'confidence' => 90],
        array_keys($services), array_values($services),
    ), 'listeners' => []]])->save();

    return $machine;
}

function fleetSections(WorkspaceStatusPage $page): array
{
    return collect((new FleetStatusReport($page->fresh()))->toArray()['sections'])
        ->map(fn ($s) => [$s['name'], array_column($s['components'], 'label')])->all();
}

test('a service can show on the page only while there’s a problem', function () {
    [$workspace, $machine] = statusMachine();

    $this->actingAs($workspace->owner)->put(statusSettings($workspace, $machine).'/status-page', statusPayload(['include_new' => false, 'services' => [
        ['key' => 'docker:aaa', 'shown' => true, 'quiet' => true, 'label' => 'App'],
        ['key' => 'systemd:nginx.service', 'shown' => true, 'quiet' => true, 'label' => 'Web'],
        ['key' => 'docker:bbb', 'shown' => true, 'label' => 'Database'],
    ]]))->assertSessionHasNoErrors();

    $page = $machine->fresh()->statusPage;
    expect($page->services['docker:aaa'])->toBe(['shown' => true, 'label' => 'App', 'quiet' => true])
        ->and($page->services['docker:bbb'])->toBe(['shown' => true, 'label' => 'Database']);

    // aaa is running, so it stays off; nginx has failed, so it shows.
    expect(array_column((new StatusReport($page))->services(), 'label'))->toBe(['Database', 'Web']);

    $this->actingAs($workspace->owner)->get(statusSettings($workspace, $machine).'/settings/status')
        ->assertInertia(fn (Assert $p) => $p->where('statusPage.services.0.quiet', true)->where('statusPage.services.1.quiet', false));
});

test('the fleet page follows machine groups, shows new machines as chosen, and takes a layout of its own', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();
    $london = MachineGroup::query()->forceCreate(['name' => 'London', 'workspace_id' => $workspace->id]);
    $a = statusFleetMachine($workspace, 'lon-1', ['docker:game' => 'game-1', 'docker:web' => 'web']);
    $b = statusFleetMachine($workspace, 'lon-2', ['docker:game' => 'game-2']);
    $c = statusFleetMachine($workspace, 'nyc-1', ['docker:game' => 'game-3']);
    $a->forceFill(['machine_group_id' => $london->id])->save();

    $settings = 'https://'.$workspace->host().'/settings/status-page';
    $this->actingAs($workspace->owner)->put($settings, fleetPayload(['machines' => [$c->id], 'machine_groups' => [$london->id]]))->assertSessionHasNoErrors();
    $page = $workspace->fleetStatusPage()->sole();

    // nyc-1 by name, lon-1 through its group; each one row until it has a page.
    expect(fleetSections($page))->toBe([['lon-1', ['Server']], ['nyc-1', ['Server']]]);

    // Joining the group puts lon-2 on the page.
    $b->forceFill(['machine_group_id' => $london->id])->save();
    expect(array_column(fleetSections($page), 0))->toBe(['lon-1', 'lon-2', 'nyc-1']);

    // New machines shown with their running services.
    $this->actingAs($workspace->owner)->put($settings, fleetPayload(['machines' => [$c->id], 'machine_groups' => [$london->id], 'new_machines' => 'services']))->assertSessionHasNoErrors();
    expect(fleetSections($page))->toBe([['lon-1', ['Game', 'Web']], ['lon-2', ['Game']], ['nyc-1', ['Game']]]);

    // A section of its own across machines, a heading, and an order.
    $this->actingAs($workspace->owner)->put($settings, fleetPayload([
        'machines' => [$c->id], 'machine_groups' => [$london->id], 'new_machines' => 'services',
        'layout' => [
            'sections' => [['name' => 'Game nodes · London', 'components' => ["{$a->id}:docker:game", "{$b->id}:docker:game"]]],
            'headings' => [(string) $c->id => 'New York', (string) $a->id => ''],
            'order' => [$c->id, $a->id],
        ],
    ]))->assertSessionHasNoErrors();

    expect($page->fresh()->layout)->toBe([
        'sections' => [['name' => 'Game nodes · London', 'components' => ["{$a->id}:docker:game", "{$b->id}:docker:game"]]],
        'headings' => [(string) $c->id => 'New York'],
        'order' => [$c->id, $a->id],
    ])
        // lon-2 is left with nothing of its own, so its heading goes.
        // Two of the same name from different machines say which is which.
        ->and(fleetSections($page))->toBe([['Game nodes · London', ['Game (lon-1)', 'Game (lon-2)']], ['New York', ['Game']], ['lon-1', ['Web']]]);

    // The settings page offers every component to arrange.
    $this->actingAs($workspace->owner)->get($settings)->assertInertia(fn (Assert $p) => $p
        ->where('page.newMachines', 'services')
        ->where('page.machineGroups', [$london->id])
        ->has('components', 4)
        ->has('groups', 1));
});

test('a machine page takes a page title, an icon and a favicon of its own', function () {
    Storage::fake('public');
    [$workspace, $machine] = statusMachine();
    $settings = statusSettings($workspace, $machine);

    // Uploading before the page is saved keeps a draft, still off.
    $this->actingAs($workspace->owner)
        ->post($settings.'/status-page/icon', ['icon' => UploadedFile::fake()->image('icon.png', 128, 128)])
        ->assertRedirect()->assertSessionHasNoErrors();
    $page = $machine->fresh()->statusPage;
    expect($page)->not->toBeNull()
        ->and($page->enabled)->toBeFalse()
        ->and($page->icon_path)->not->toBeNull();
    Storage::disk('public')->assertExists($page->icon_path);

    $this->post($settings.'/status-page/favicon', ['favicon' => UploadedFile::fake()->create('favicon.ico', 4, 'image/x-icon')])
        ->assertSessionHasNoErrors();
    // No SVGs: they can carry scripts.
    $this->post($settings.'/status-page/favicon', ['favicon' => UploadedFile::fake()->create('evil.svg', 1, 'image/svg+xml')])
        ->assertSessionHasErrors('favicon');
    $this->post($settings.'/status-page/banner', ['banner' => UploadedFile::fake()->image('b.png')])->assertNotFound();

    $this->put($settings.'/status-page', statusPayload(['page_title' => 'Acme | Status']))->assertSessionHasNoErrors();
    $page->refresh();

    auth()->logout();
    $response = $this->get('/status/web-one')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('status.title', 'Acme status')
        ->where('pageTitle', 'Acme | Status')
        ->where('brand.logoUrl', $page->iconUrl())
        ->where('favicon', $page->faviconUrl()));
    expect($response->getContent())
        ->toContain('<link rel="icon" href="'.$page->faviconUrl().'">')
        ->not->toContain('/favicon.png')
        ->toContain('<meta property="og:title" content="Acme | Status · Partial outage">');

    // Removing them goes back to the workspace logo and ServerOS's favicon.
    $this->actingAs($workspace->owner)->delete($settings.'/status-page/icon')->assertRedirect();
    $this->delete($settings.'/status-page/favicon')->assertRedirect();
    Storage::disk('public')->assertMissing($page->icon_path);
    Storage::disk('public')->assertMissing($page->favicon_path);
    auth()->logout();
    $this->get('/status/web-one')->assertInertia(fn (Assert $p) => $p
        ->where('brand.logoUrl', null)
        ->where('favicon', null));
});

test('the fleet page takes a page title, an icon and a favicon, for people who can manage machines', function () {
    Storage::fake('public');
    [$workspace] = statusMachine(Plan::Business);
    $settings = 'https://'.$workspace->host().'/settings/status-page';

    $this->actingAs($workspace->owner)
        ->post($settings.'/favicon', ['favicon' => UploadedFile::fake()->image('favicon.png', 64, 64)])
        ->assertSessionHasNoErrors();
    $this->put($settings, fleetPayload(['page_title' => 'Acme uptime']))->assertSessionHasNoErrors();

    $page = $workspace->fresh()->fleetStatusPage;
    expect($page->page_title)->toBe('Acme uptime')->and($page->favicon_path)->not->toBeNull();

    $this->get($settings)->assertInertia(fn (Assert $p) => $p
        ->where('page.pageTitle', 'Acme uptime')
        ->where('page.faviconUrl', $page->faviconUrl())
        ->where('page.iconUrl', null));

    $member = User::factory()->create();
    $workspace->members()->attach($member, ['role' => 'viewer']);
    $this->actingAs($member)->post($settings.'/icon', ['icon' => UploadedFile::fake()->image('icon.png')])->assertForbidden();

    // Deleting the page takes its pictures with it.
    $path = $page->favicon_path;
    $page->delete();
    Storage::disk('public')->assertMissing($path);
});
