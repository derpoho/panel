<?php

use App\Enums\Plan;
use App\Models\Activity;
use App\Models\DaemonEnrolment;
use App\Models\DaemonRelease;
use App\Models\Machine;
use App\Models\MachineGroup;
use App\Models\Workspace;
use App\Notifications\MonitorAlert;
use App\Support\Activity\ActivityLine;
use App\Support\DaemonUpdates;
use App\Support\Machines\MachinePolicies;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/*
 * Machine details, policies by them, and pinned and
 * approved daemon updates with their alerts.
 */

beforeEach(function () {
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['ok' => true])]);
});

function detailsMachine(Workspace $workspace, string $name = 'node-1', array $facts = []): Machine
{
    $machine = Machine::factory()->for($workspace)->create(['name' => $name, 'provider' => 'Other']);
    $machine->forceFill([
        'uid' => (string) Str::uuid(), 'certificate_serial' => bin2hex(random_bytes(6)), 'daemon_version' => 'v0.1.1-pre.24',
        'last_seen_at' => now(), 'connected_at' => now(), 'facts' => ['hostname' => $name, 'arch' => 'x86_64', ...$facts],
    ])->save();

    return $machine->fresh();
}

function detailsHello(Machine $machine, array $hello = []): void
{
    test()->postJson('/api/gateway/hello', [
        'serial' => $machine->certificate_serial,
        'hello' => ['machine_id' => $machine->uid, 'daemon_version' => 'v0.1.1-pre.24', 'supported_majors' => [1], 'facts' => ['hostname' => $machine->name, 'arch' => 'x86_64'], ...$hello],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();
}

/** @return list<array<string, mixed>> */
function detailsControls(string $type = 'reconfigure'): array
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $r) => str_ends_with($r->url(), '/control') && ($r->data()['control']['type'] ?? null) === $type)
        ->map(fn (Request $r) => ['uid' => Str::between($r->url(), '/machines/', '/control'), ...$r->data()['control']])
        ->values()->all();
}

test('a provider or region set by hand stays; emptied, detection fills it in again', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = detailsMachine($workspace);
    $url = 'https://'.$workspace->host().'/machines/'.$machine->id;
    $hetzner = ['hostname' => 'node-1', 'arch' => 'x86_64', 'hosting' => ['provider' => 'Hetzner', 'region' => 'eu-central', 'source' => 'instance_metadata']];

    detailsHello($machine, ['facts' => $hetzner]);
    expect($machine->fresh())->provider->toBe('Hetzner')->region->toBe('eu-central');

    $this->actingAs($workspace->owner)->patch($url, [
        'name' => 'node-1', 'environment' => 'production', 'provider' => 'OVH', 'region' => 'lon1', 'tier' => 'performance',
        'labels' => [['key' => 'customer', 'value' => 'acme'], ['key' => 'rack', 'value' => 'b2']],
    ])->assertSessionHasNoErrors();
    detailsHello($machine, ['facts' => $hetzner]);
    expect($machine->fresh())
        ->provider->toBe('OVH')->provider_manual->toBeTrue()
        ->region->toBe('lon1')->tier->toBe('performance')
        ->labels->toBe(['customer' => 'acme', 'rack' => 'b2']);
    expect(ActivityLine::sentence(Activity::query()->where('action', 'machine.metadata')->sole()))
        ->toContain('changed the details of node-1');

    $this->actingAs($workspace->owner)->patch($url, ['name' => 'node-1', 'environment' => 'production', 'provider' => '', 'region' => '', 'tier' => '', 'labels' => []])->assertSessionHasNoErrors();
    expect($machine->fresh())->provider->toBe('Hetzner')->provider_manual->toBeFalse()->region->toBe('eu-central')->tier->toBeNull()->labels->toBeNull();

    $this->actingAs($workspace->owner)->patch($url, ['name' => 'node-1', 'environment' => 'production', 'labels' => [['key' => '!!', 'value' => 'x']]])
        ->assertSessionHasErrors('labels');
});

test('details can be set on many machines at once', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $a = detailsMachine($workspace, 'a');
    $b = detailsMachine($workspace, 'b');
    $b->forceFill(['labels' => ['old' => 'yes', 'customer' => 'zed']])->save();
    $other = detailsMachine(Workspace::factory()->create(), 'theirs');
    $url = 'https://'.$workspace->host().'/machines/metadata';

    $this->actingAs($workspace->owner)->post($url, ['machines' => [$a->id, $other->id], 'tier' => 'budget'])->assertSessionHasErrors('machines.1');

    $this->actingAs($workspace->owner)->post($url, [
        'machines' => [$a->id, $b->id], 'region' => 'lon1', 'tier' => 'budget',
        'labels' => [['key' => 'customer', 'value' => 'acme']], 'remove_labels' => ['old'],
    ])->assertSessionHasNoErrors();

    expect($a->fresh())->region->toBe('lon1')->tier->toBe('budget')->labels->toBe(['customer' => 'acme'])
        ->and($b->fresh()->labels)->toBe(['customer' => 'acme'])
        ->and($b->fresh()->provider)->toBe('Other');
    expect(Activity::query()->where('action', 'machines.metadata')->sole()->meta)->toMatchArray(['machines' => 2]);
});

test('a policy can apply by machine details, after a machine’s own pick and its group’s', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();
    $fast = detailsMachine($workspace, 'fast');
    $cheap = detailsMachine($workspace, 'cheap');
    $url = 'https://'.$workspace->host();
    $default = MachinePolicies::defaultFor($workspace);

    $this->actingAs($workspace->owner)->post($url.'/settings/machine-policies', [
        'name' => 'Performance', ...$default->settings(), 'logs' => false,
        'applies_to' => [['field' => 'tier', 'value' => 'performance'], ['field' => 'nonsense', 'value' => 'x']],
    ])->assertSessionHasErrors('applies_to.1.field');
    $this->actingAs($workspace->owner)->post($url.'/settings/machine-policies', [
        'name' => 'Performance', ...$default->settings(), 'logs' => false,
        'applies_to' => [['field' => 'tier', 'value' => 'performance'], ['field' => 'label:customer', 'value' => 'Acme']],
    ])->assertSessionHasNoErrors();
    $performance = $workspace->machinePolicies()->where('name', 'Performance')->sole();
    expect(detailsControls())->toBe([]);

    // Tagging a machine moves it onto the policy, and it's sent the change.
    $this->actingAs($workspace->owner)->post($url.'/machines/metadata', ['machines' => [$fast->id], 'tier' => 'Performance'])->assertSessionHasNoErrors();
    expect(MachinePolicies::resolve($fast->fresh()))->toMatchArray(['from' => 'rule'])
        ->and(MachinePolicies::for($fast->fresh())->id)->toBe($performance->id)
        ->and(detailsControls())->toHaveCount(1)
        ->and(detailsControls()[0])->toMatchArray(['uid' => $fast->uid, 'logs' => false]);

    // A label matches too, in any case.
    $cheap->forceFill(['labels' => ['customer' => 'acme']])->save();
    expect(MachinePolicies::for($cheap->fresh())->id)->toBe($performance->id);

    // Its group's policy comes first.
    $loud = $workspace->machinePolicies()->create(['name' => 'Loud']);
    MachineGroup::query()->forceCreate(['workspace_id' => $workspace->id, 'name' => 'Racks', 'machine_policy_id' => $loud->id]);
    $fast->forceFill(['machine_group_id' => MachineGroup::query()->sole()->id])->save();
    expect(MachinePolicies::for($fast->fresh())->id)->toBe($loud->id);

    // Rules shown, and the AI tools see them.
    $this->actingAs($workspace->owner)->get($url.'/settings/machine-policies')->assertOk();
    $listed = mcpCall($workspace, mcpKey($workspace), 'list_machine_policies');
    expect(collect($listed['data']['policies'])->firstWhere('name', 'Performance')['appliesTo'])->toHaveCount(2);
});

test('provisioning scripts tag a machine as it connects, and find machines by their details', function () {
    config()->set('daemon.install_ready', true);
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();
    $key = mcpKey($workspace);

    $added = mcpCall($workspace, $key, 'add_machine', ['region' => 'lon1', 'tier' => 'budget', 'labels' => ['customer' => 'acme']]);
    expect($added['error'])->toBeFalse()
        ->and(DaemonEnrolment::query()->sole()->metadata)->toBe(['region' => 'lon1', 'tier' => 'budget', 'labels' => ['customer' => 'acme']]);
    expect(mcpCall($workspace, $key, 'add_machine', ['labels' => ['bad key!' => 'x']])['error'])->toBeTrue();

    $machine = detailsMachine($workspace, 'tagged');
    mcpCall($workspace, $key, 'update_machine', ['machine' => $machine->id, 'tier' => 'performance', 'labels' => ['customer' => 'acme', 'rack' => 'b2']]);
    detailsMachine($workspace, 'plain');

    expect(collect(mcpCall($workspace, $key, 'list_machines', ['tier' => 'PERFORMANCE'])['data']['machines'])->pluck('name')->all())->toBe(['tagged'])
        ->and(collect(mcpCall($workspace, $key, 'list_machines', ['label' => 'rack'])['data']['machines'])->pluck('name')->all())->toBe(['tagged'])
        ->and(collect(mcpCall($workspace, $key, 'list_machines', ['label' => 'customer=nope'])['data']['machines']))->toBeEmpty();
});

test('a pinned policy keeps machines on one release, and a pin set elsewhere is put back', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = detailsMachine($workspace);
    foreach (['v0.1.1-pre.23', 'v0.1.1-pre.25'] as $version) {
        DaemonRelease::query()->create(['version' => $version, 'arch' => 'amd64', 'channel' => 'stable', 'sha256' => str_repeat('a', 64), 'signature' => base64_encode(str_repeat('s', 64))]);
    }
    $policy = MachinePolicies::defaultFor($workspace);
    $url = 'https://'.$workspace->host().'/settings/machine-policies/'.$policy->id;

    $this->actingAs($workspace->owner)->put($url, ['name' => 'Default', ...$policy->settings(), 'updates' => 'pinned', 'pinned_version' => null])->assertSessionHasErrors('pinned_version');
    $this->actingAs($workspace->owner)->put($url, ['name' => 'Default', ...$policy->settings(), 'updates' => 'pinned', 'pinned_version' => 'v9.9.9'])->assertSessionHasErrors('pinned_version');
    $this->actingAs($workspace->owner)->put($url, ['name' => 'Default', ...$policy->settings(), 'updates' => 'pinned', 'pinned_version' => 'v0.1.1-pre.23'])->assertSessionHasNoErrors();
    expect(detailsControls()[0])->toMatchArray(['updates_automatic' => false, 'pinned_version' => 'v0.1.1-pre.23']);

    // The pin, older than what it runs, is what it can move to; nothing newer is pushed.
    expect(DaemonUpdates::available($machine->fresh())?->version)->toBe('v0.1.1-pre.23')
        ->and(app(DaemonUpdates::class)->push())->toMatchArray(['offered' => [], 'waiting' => []]);

    // Unpinned on the machine by hand: it's pinned again when it says hello.
    detailsHello($machine, ['pinned_version' => null, 'updates_automatic' => false]);
    expect(collect(detailsControls())->last())->toBe(['uid' => $machine->uid, 'type' => 'reconfigure', 'pinned_version' => 'v0.1.1-pre.23']);
    $before = count(detailsControls());
    detailsHello($machine, ['pinned_version' => 'v0.1.1-pre.23', 'updates_automatic' => false]);
    expect(detailsControls())->toHaveCount($before);
});

test('machines that ask first are told a release is waiting, and when they’ve updated', function () {
    Notification::fake();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    MachinePolicies::defaultFor($workspace)->update(['updates' => 'approve']);
    $one = detailsMachine($workspace, 'one');
    $two = detailsMachine($workspace, 'two');
    $release = DaemonRelease::query()->create(['version' => 'v0.1.1-pre.25', 'arch' => 'amd64', 'channel' => 'stable', 'sha256' => str_repeat('a', 64), 'signature' => base64_encode(str_repeat('s', 64)), 'notes_url' => 'https://github.com/serveroshq/daemon/releases/tag/v0.1.1-pre.25']);

    $result = app(DaemonUpdates::class)->push();
    expect($result['offered'])->toBe([])->and($result['waiting'])->toBe(['one', 'two'])
        ->and(detailsControls('self_update'))->toBe([]);
    Notification::assertSentTimes(MonitorAlert::class, 1);
    Notification::assertSentTo($workspace->owner, MonitorAlert::class, fn (MonitorAlert $n) => $n->subject === 'Daemon v0.1.1-pre.25 is ready to install');
    expect(ActivityLine::sentence(Activity::query()->where('action', 'daemon.update_waiting')->sole()))->toBe('v0.1.1-pre.25 is waiting for approval on 2 machines');

    // Approved for every machine the policy covers.
    $this->actingAs($workspace->owner)->post('https://'.$workspace->host().'/settings/machine-policies/'.MachinePolicies::defaultFor($workspace)->id.'/install-updates')->assertSessionHasNoErrors();
    expect(collect(detailsControls('self_update'))->pluck('uid')->sort()->values()->all())->toBe(collect([$one->uid, $two->uid])->sort()->values()->all())
        ->and(collect(detailsControls('self_update'))->every(fn (array $c) => $c['approved'] === true))->toBeTrue();

    // Back on the new release: Activity, and the alert.
    detailsHello($one, ['daemon_version' => 'v0.1.1-pre.25']);
    expect(ActivityLine::sentence(Activity::query()->where('action', 'machine.daemon_updated')->sole()))->toBe('one is on a new daemon v0.1.1-pre.24 to v0.1.1-pre.25');
    Notification::assertSentTo($workspace->owner, MonitorAlert::class, fn (MonitorAlert $n) => $n->subject === 'one is on daemon v0.1.1-pre.25');

    // The same version again, or an automatic one: no alert.
    detailsHello($one, ['daemon_version' => 'v0.1.1-pre.25']);
    Notification::assertSentTimes(MonitorAlert::class, 2);

    // The machine page links every change in the source.
    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/machines/'.$two->id.'/settings')
        ->assertInertia(fn ($page) => $page->where('policy.daemon.compareUrl', 'https://github.com/serveroshq/daemon/compare/v0.1.1-pre.24...v0.1.1-pre.25'));
    expect($release->version)->toBe('v0.1.1-pre.25');
});
