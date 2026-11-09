<?php

use App\Enums\Plan;
use App\Mcp\Scopes;
use App\Models\Activity;
use App\Models\DaemonEnrolment;
use App\Models\DaemonRelease;
use App\Models\LogEntry;
use App\Models\Machine;
use App\Models\MachineGroup;
use App\Models\MachinePolicy;
use App\Models\McpToken;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use App\Support\DaemonUpdates;
use App\Support\Logs\LogIngest;
use App\Support\Machines\MachinePolicies;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Machine policies: what a workspace's machines collect and send,
 * followed from the machine's own pick, else its group's, else the
 * default, and sent to the daemon at enrolment, on change and on hello.
 */

beforeEach(function () {
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['ok' => true])]);
});

function policyMachine(Workspace $workspace, string $name = 'web-1', string $version = 'v0.1.1-pre.24'): Machine
{
    $machine = Machine::factory()->for($workspace)->create(['name' => $name]);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'certificate_serial' => bin2hex(random_bytes(6)), 'daemon_version' => $version, 'last_seen_at' => now(), 'connected_at' => now()])->save();

    return $machine->fresh();
}

/** @return list<array<string, mixed>> reconfigure messages sent, with the machine's uid */
function policyControls(): array
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $r) => str_ends_with($r->url(), '/control') && ($r->data()['control']['type'] ?? null) === 'reconfigure')
        ->map(fn (Request $r) => ['uid' => Str::between($r->url(), '/machines/', '/control'), ...$r->data()['control']])
        ->values()->all();
}

function policyUrl(Workspace $workspace, string $path = ''): string
{
    return 'https://'.$workspace->host().'/settings/machine-policies'.$path;
}

function policyForm(MachinePolicy $policy, array $changes = []): array
{
    return ['name' => $policy->name, ...$policy->settings(), ...$changes];
}

function policyCsr(): string
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1', 'private_key_bits' => 384]);
    $csr = openssl_csr_new(['commonName' => 'pending'], $key);
    openssl_csr_export($csr, $pem);

    return $pem;
}

test('a machine follows its own policy, else its group’s, else the default', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create(['welcome' => ['segment' => 'hosting']]);
    $machine = policyMachine($workspace);

    // The default is made when first needed, and matches a machine with no
    // policy: making it changes nothing, whatever they told the welcome flow.
    $default = MachinePolicies::defaultFor($workspace);
    expect($default->is_default)->toBeTrue()
        ->and($default->settings())->toBe(MachinePolicy::PRESETS['own'])
        ->and(MachinePolicies::resolve($machine)['from'])->toBe('default');

    $quiet = $workspace->machinePolicies()->create(['name' => 'Quiet', 'discovery' => false]);
    $group = MachineGroup::query()->forceCreate(['workspace_id' => $workspace->id, 'name' => 'Game nodes', 'machine_policy_id' => $quiet->id]);
    $machine->forceFill(['machine_group_id' => $group->id])->save();
    expect(MachinePolicies::resolve($machine->fresh()))->toMatchArray(['policy' => $quiet->fresh(), 'from' => 'group']);

    $own = $workspace->machinePolicies()->create(['name' => 'Loud']);
    $machine->forceFill(['machine_policy_id' => $own->id])->save();
    expect(MachinePolicies::for($machine->fresh())->id)->toBe($own->id);

    expect(MachinePolicies::following($quiet)->count())->toBe(0)
        ->and(MachinePolicies::following($own)->pluck('id')->all())->toBe([$machine->id]);
});

test('a join command can carry a policy and a group, and the daemon gets the settings with its enrolment', function () {
    config()->set('daemon.install_ready', true);
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $quiet = $workspace->machinePolicies()->create(['name' => 'Quiet', 'discovery' => false, 'logs' => false, 'updates' => 'approve']);
    $group = MachineGroup::query()->forceCreate(['workspace_id' => $workspace->id, 'name' => 'London']);
    $dir = sys_get_temp_dir().'/serveros-ca-test-'.bin2hex(random_bytes(5));
    config()->set('daemon.ca_cert', $dir.'/ca.crt');
    config()->set('daemon.ca_key', $dir.'/ca.key');
    Artisan::call('daemon:ca', ['action' => 'init']);

    // Another workspace's policy can't be picked.
    $other = Workspace::factory()->create()->machinePolicies()->create(['name' => 'Theirs']);
    $this->actingAs($workspace->owner)->postJson('https://'.$workspace->host().'/machines/connect', ['policy' => $other->id])->assertUnprocessable();

    try {
        $command = $this->actingAs($workspace->owner)
            ->postJson('https://'.$workspace->host().'/machines/connect', ['policy' => $quiet->id, 'group' => $group->id])
            ->assertOk()->json('command');
        preg_match('/--token=\x27([a-f0-9]{64})\x27/', $command, $match);
        expect(DaemonEnrolment::query()->sole())->machine_policy_id->toBe($quiet->id)->machine_group_id->toBe($group->id);

        $this->app['auth']->forgetGuards();
        $enrol = $this->postJson('/api/daemon/enrol', [
            'token' => $match[1], 'csr' => policyCsr(), 'daemon_version' => 'v0.1.1-pre.24',
            'protocol_majors' => [1], 'facts' => ['hostname' => 'lon-1', 'os' => 'ubuntu', 'interfaces' => []],
        ])->assertCreated();

        expect($enrol->json('settings'))->toBe(['discovery' => false, 'logs' => false, 'log_ip_masking' => 'off', 'security_watch' => true, 'error_reports' => true, 'updates_automatic' => false, 'pinned_version' => null]);
        $machine = Machine::query()->where('uid', $enrol->json('machine_id'))->sole();
        expect($machine->machine_policy_id)->toBe($quiet->id)
            ->and($machine->machine_group_id)->toBe($group->id);
    } finally {
        @unlink($dir.'/ca.crt');
        @unlink($dir.'/ca.key');
        @rmdir($dir);
    }
});

test('on hello the policy wins: only what the daemon has differently is sent, and only what it reports', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = policyMachine($workspace);
    MachinePolicies::defaultFor($workspace)->update(['logs' => false, 'log_ip_masking' => 'partial']);
    $hello = fn (array $extra) => $this->postJson('/api/gateway/hello', [
        'serial' => $machine->certificate_serial,
        'hello' => ['machine_id' => $machine->uid, 'daemon_version' => 'v0.1.1-pre.24', 'supported_majors' => [1], 'facts' => ['hostname' => 'web-1'], ...$extra],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();

    $hello(['discovery' => true, 'logs' => true, 'log_ip_masking' => 'hash', 'security_watch' => true, 'updates_automatic' => true]);
    expect(policyControls())->toHaveCount(1)
        ->and(policyControls()[0])->toBe(['uid' => $machine->uid, 'type' => 'reconfigure', 'logs' => false, 'log_ip_masking' => 'partial'])
        ->and($machine->fresh()->log_ip_masking)->toBe('partial');

    // Already as the policy says: nothing to send.
    $hello(['discovery' => true, 'logs' => false, 'log_ip_masking' => 'partial', 'security_watch' => true, 'updates_automatic' => true]);
    expect(policyControls())->toHaveCount(1);

    // An older daemon reports only masking: it isn't asked for the rest.
    $hello(['log_ip_masking' => 'off']);
    expect(policyControls()[1])->toBe(['uid' => $machine->uid, 'type' => 'reconfigure', 'log_ip_masking' => 'partial']);
});

test('logs off keeps nothing a daemon still sends', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = policyMachine($workspace);
    $line = ['lines' => [['service' => 'docker:a', 'name' => 'shop', 'ts' => now()->getTimestampMs(), 'stream' => 'stdout', 'line' => 'hello']]];

    expect(LogIngest::store($machine, $line))->toBe(1);
    MachinePolicies::defaultFor($workspace)->update(['logs' => false]);
    expect(LogIngest::store($machine->fresh(), $line))->toBe(0)
        ->and(LogEntry::query()->count())->toBe(1);
});

test('managers create, change, make default and delete policies; changes reach the machines that follow them', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $web = policyMachine($workspace, 'web-1');
    $offline = policyMachine($workspace, 'web-2');
    // A gateway that doesn't have the second machine.
    Http::swap(new HttpFactory);
    Http::fake([
        "127.0.0.1:8090/machines/{$offline->uid}/*" => Http::response([], 404),
        '127.0.0.1:8090/*' => Http::response(['ok' => true]),
    ]);

    $this->actingAs($workspace->owner)->get(policyUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page->component('workspace/settings/MachinePolicies')
            ->has('policies', 1)
            ->where('policies.0.isDefault', true)
            ->where('policies.0.machines', 2));

    $default = MachinePolicies::defaultFor($workspace);
    $this->actingAs($workspace->owner)->put(policyUrl($workspace, '/'.$default->id), policyForm($default, ['discovery' => false]))->assertSessionHasNoErrors();
    expect(collect(policyControls())->pluck('discovery', 'uid')->all())->toBe([$web->uid => false, $offline->uid => false])
        ->and($web->fresh()->policy_pending)->toBeFalse()
        ->and($offline->fresh()->policy_pending)->toBeTrue();
    expect(ActivityLine::sentence(Activity::query()->where('action', 'machine_policy.update')->sole()))
        ->toBe($workspace->owner->name.' changed the machine policy Default 2 machines follow it');

    $this->actingAs($workspace->owner)->post(policyUrl($workspace), [...policyForm($default), 'name' => 'Default'])->assertSessionHasErrors('name');
    $this->actingAs($workspace->owner)->post(policyUrl($workspace), [...policyForm($default), 'name' => 'Hosting', 'logs' => false])->assertSessionHasNoErrors();
    $hosting = $workspace->machinePolicies()->where('name', 'Hosting')->sole();

    // Making it the default moves both machines onto it.
    $before = count(policyControls());
    $this->actingAs($workspace->owner)->post(policyUrl($workspace, '/'.$hosting->id.'/default'))->assertSessionHasNoErrors();
    expect($hosting->fresh()->is_default)->toBeTrue()
        ->and($default->fresh()->is_default)->toBeFalse()
        ->and(count(policyControls()) - $before)->toBe(2);

    // The default can't go; another can, and its machines fall back.
    $this->actingAs($workspace->owner)->delete(policyUrl($workspace, '/'.$hosting->id))->assertSessionHasErrors('policy');
    $web->forceFill(['machine_policy_id' => $default->id])->save();
    $this->actingAs($workspace->owner)->delete(policyUrl($workspace, '/'.$default->id))->assertSessionHasNoErrors();
    expect($web->fresh()->machine_policy_id)->toBeNull()
        ->and(MachinePolicies::for($web->fresh())->id)->toBe($hosting->id);

    // Viewers see them but can't change them.
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)->get(policyUrl($workspace))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canManage', false));
    $this->actingAs($viewer)->put(policyUrl($workspace, '/'.$hosting->id), policyForm($hosting, ['logs' => true]))->assertForbidden();
});

test('a group’s policy reaches its machines that didn’t pick their own', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $a = policyMachine($workspace, 'node-a');
    $b = policyMachine($workspace, 'node-b');
    $quiet = $workspace->machinePolicies()->create(['name' => 'Quiet', 'logs' => false]);
    $own = $workspace->machinePolicies()->create(['name' => 'Own']);
    $b->forceFill(['machine_policy_id' => $own->id])->save();
    $url = 'https://'.$workspace->host().'/machine-groups';

    $this->actingAs($workspace->owner)->post($url, ['name' => 'Nodes', 'machines' => [$a->id, $b->id], 'policy' => $quiet->id])->assertSessionHasNoErrors();
    expect(collect(policyControls())->pluck('uid')->all())->toBe([$a->uid]);

    // Renaming it without saying the policy leaves the policy.
    $group = MachineGroup::query()->sole();
    $this->actingAs($workspace->owner)->put($url.'/'.$group->id, ['name' => 'Game nodes', 'machines' => [$a->id, $b->id]])->assertSessionHasNoErrors();
    expect($group->fresh()->machine_policy_id)->toBe($quiet->id)
        ->and(policyControls())->toHaveCount(1);

    // Deleting it sends its machines back to the default.
    $this->actingAs($workspace->owner)->delete($url.'/'.$group->id)->assertSessionHasNoErrors();
    expect(policyControls())->toHaveCount(2)
        ->and(policyControls()[1])->toMatchArray(['uid' => $a->uid, 'logs' => true]);
});

test('a machine can pick its policy, and approve a daemon update its policy holds back', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = policyMachine($workspace, 'web-1', 'v0.1.1-pre.23');
    $machine->forceFill(['facts' => ['hostname' => 'web-1', 'arch' => 'x86_64']])->save();
    $careful = $workspace->machinePolicies()->create(['name' => 'Careful', 'updates' => 'approve']);
    $base = 'https://'.$workspace->host().'/machines/'.$machine->id;

    $this->actingAs($workspace->owner)->put($base.'/policy', ['policy' => $careful->id])->assertSessionHasNoErrors();
    expect(policyControls()[0])->toMatchArray(['uid' => $machine->uid, 'updates_automatic' => false])
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'machine.policy')->sole()))->toBe($workspace->owner->name.' changed the policy of web-1 to Careful');

    DaemonRelease::query()->create(['version' => 'v0.1.1-pre.24', 'arch' => 'amd64', 'channel' => 'stable', 'sha256' => str_repeat('a', 64), 'signature' => base64_encode(str_repeat('s', 64)), 'notes_url' => 'https://github.com/serveroshq/daemon/releases/tag/v0.1.1-pre.24']);
    $this->actingAs($workspace->owner)->get($base.'/settings')->assertInertia(fn (Assert $page) => $page
        ->where('policy.current.name', 'Careful')
        ->where('policy.from', 'machine')
        ->where('policy.supported', false)
        ->where('policy.daemon.available', 'v0.1.1-pre.24'));

    // Release pushes skip it; approving installs it.
    expect(app(DaemonUpdates::class)->push()['offered'])->toBe([]);
    $this->actingAs($workspace->owner)->post($base.'/daemon-update')->assertSessionHasNoErrors();
    Http::assertSent(fn (Request $r) => ($r->data()['control']['type'] ?? null) === 'self_update' && $r->data()['control']['approved'] === true);

    // Back to following the default.
    $this->actingAs($workspace->owner)->put($base.'/policy', ['policy' => null])->assertSessionHasNoErrors();
    expect($machine->fresh()->machine_policy_id)->toBeNull();
});

test('a new status page shows new services only when the policy says to', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = policyMachine($workspace);

    expect(StatusPage::draftFor($machine)->include_new)->toBeFalse();
    MachinePolicies::defaultFor($workspace)->update(['status_page_new' => true]);
    expect(StatusPage::draftFor($machine->fresh())->include_new)->toBeTrue();
});

test('with discovery off, a new machine is done once it connects', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    MachinePolicies::defaultFor($workspace)->update(['discovery' => false]);
    $machine = policyMachine($workspace);
    $enrolment = DaemonEnrolment::query()->create(['workspace_id' => $workspace->id, 'machine_id' => $machine->id, 'token_hash' => str_repeat('a', 64), 'expires_at' => now()->addMinutes(5), 'used_at' => now()]);

    $this->actingAs($workspace->owner)->getJson('https://'.$workspace->host().'/machines/connect/'.$enrolment->id.'/status')
        ->assertOk()->assertJson(['state' => 'discovered', 'discovery' => false]);
});

test('the welcome flow sets the default policy: a preset from what they run, then what they choose', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create(['onboarded_at' => null, 'trial_ends_at' => now()->addDays(14)]);
    $base = 'https://'.$workspace->host().'/welcome';

    $this->actingAs($owner)->post($base.'/about', ['segment' => 'hosting', 'servers' => '20+', 'team' => true])->assertSessionHasNoErrors();
    expect(MachinePolicies::defaultFor($workspace)->settings())->toBe(MachinePolicy::PRESETS['hosting']);

    $this->actingAs($owner)->get($base)->assertInertia(fn (Assert $page) => $page
        ->where('answers.control', null)
        ->where('presets.hosting.logs', false));

    $chosen = [...MachinePolicy::PRESETS['hosting'], 'logs' => true, 'log_ip_masking' => 'hash'];
    $this->actingAs($owner)->post($base.'/control', $chosen)->assertSessionHasNoErrors();
    expect(MachinePolicies::defaultFor($workspace)->settings())->toBe($chosen);

    // Answering again keeps what they chose.
    $this->actingAs($owner)->post($base.'/about', ['segment' => 'own', 'servers' => '1', 'team' => false]);
    expect(MachinePolicies::defaultFor($workspace)->logs)->toBeTrue()
        ->and(MachinePolicies::defaultFor($workspace)->updates)->toBe('approve');
    $this->actingAs($owner)->get($base)->assertInertia(fn (Assert $page) => $page->where('answers.control.log_ip_masking', 'hash'));
});

test('the API and AI apps can list, create and change policies, and connect or move machines with one', function () {
    config()->set('daemon.install_ready', true);
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();
    $machine = policyMachine($workspace);
    $group = MachineGroup::query()->forceCreate(['workspace_id' => $workspace->id, 'name' => 'London']);
    $key = mcpKey($workspace);

    $created = mcpCall($workspace, $key, 'create_machine_policy', ['name' => 'Hosting', 'logs' => false, 'updates' => 'approve']);
    expect($created['error'])->toBeFalse()
        ->and($created['data']['policy'])->toMatchArray(['name' => 'Hosting', 'logs' => false, 'discovery' => true, 'updates' => 'approve']);

    $added = mcpCall($workspace, $key, 'add_machine', ['policy' => 'hosting', 'group' => 'London']);
    expect($added['error'])->toBeFalse()
        ->and($added['data']['policy']['name'])->toBe('Hosting')
        ->and(DaemonEnrolment::query()->sole())->machine_group_id->toBe($group->id);
    expect(mcpCall($workspace, $key, 'add_machine', ['policy' => 'Nope'])['error'])->toBeTrue();

    $moved = mcpCall($workspace, $key, 'update_machine', ['machine' => $machine->id, 'policy' => 'Hosting']);
    expect($moved['data']['changed']['policy'])->toMatchArray(['from' => 'Default', 'to' => 'Hosting', 'sentToMachine' => true]);

    $changed = mcpCall($workspace, $key, 'update_machine_policy', ['policy' => 'Hosting', 'discovery' => false]);
    expect($changed['data']['policy'])->toMatchArray(['discovery' => false, 'machines' => 1])
        ->and(collect(policyControls())->last())->toMatchArray(['uid' => $machine->uid, 'discovery' => false]);

    $listed = mcpCall($workspace, $key, 'list_machine_policies');
    expect(collect($listed['data']['policies'])->pluck('name')->all())->toBe(['Default', 'Hosting']);

    [, $apiKey] = McpToken::issueKey($workspace, $workspace->owner, 'CI', Scopes::ALL, McpToken::API);
    $this->withToken($apiKey)->getJson('/api/v1/machine-policies')->assertOk()->assertJsonPath('data.policies.1.name', 'Hosting');
});

test('error reports can be turned off by policy: a daemon that reports them on is told, an older one is not asked', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = policyMachine($workspace);
    MachinePolicies::defaultFor($workspace)->update(['error_reports' => false]);
    $hello = fn (array $extra) => $this->postJson('/api/gateway/hello', [
        'serial' => $machine->certificate_serial,
        'hello' => ['machine_id' => $machine->uid, 'daemon_version' => 'v0.1.1-pre.30', 'supported_majors' => [1], 'facts' => ['hostname' => 'web-1'], ...$extra],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();

    $hello(['discovery' => true, 'logs' => true, 'log_ip_masking' => 'off', 'security_watch' => true, 'error_reports' => true, 'updates_automatic' => true]);
    expect(policyControls())->toHaveCount(1)
        ->and(policyControls()[0])->toBe(['uid' => $machine->uid, 'type' => 'reconfigure', 'error_reports' => false]);

    // A daemon from before error reports were a setting doesn't say: nothing to send.
    $hello(['discovery' => true, 'logs' => true, 'log_ip_masking' => 'off', 'security_watch' => true, 'updates_automatic' => true]);
    expect(policyControls())->toHaveCount(1);

    expect(MachinePolicies::defaultFor($workspace)->toDaemon())->toHaveKey('error_reports', false)
        ->and(MachinePolicies::defaultFor($workspace)->toPageProps())->toHaveKey('error_reports', false);
});
