<?php

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Firewall;
use App\Support\Health\WorkspaceHealth;
use App\Support\SecurityScan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A machine whose daemon reports ufw on, blocking by default: SSH and the
 * web open, PostgreSQL open to the internet, Redis closed, and a Docker
 * container published on 8080.
 *
 * @param  array<string, mixed>|false|null  $firewall  false: a daemon too old to report it
 * @return array{0: Workspace, 1: Machine, 2: string}
 */
function firewallMachine(array|false|null $firewall = null): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'db-1']);
    $listener = fn (int $port, string $exe, string $address = '0.0.0.0') => ['proto' => 'tcp', 'address' => $address, 'port' => $port, 'pid' => 1, 'exe' => $exe, 'user' => 'root'];
    $inventory = [
        'scanned_at' => now()->getTimestamp(),
        'complete' => true,
        'services' => [],
        'listeners' => [
            $listener(22, '/usr/sbin/sshd'),
            $listener(443, '/usr/sbin/nginx'),
            $listener(5432, '/usr/lib/postgresql/16/bin/postgres'),
            $listener(6379, '/usr/bin/redis-server'),
            $listener(8080, '/usr/bin/docker-proxy'),
            $listener(3306, '/usr/sbin/mysqld', '127.0.0.1'),
        ],
        'warnings' => [],
    ];
    if ($firewall !== false) {
        $inventory['firewall'] = $firewall ?? [
            'backend' => 'ufw',
            'installed' => true,
            'active' => true,
            'default_incoming' => 'deny',
            'rules' => [
                ['verdict' => 'allow', 'ports' => [['start' => 22, 'end' => 22]], 'proto' => 'tcp', 'source' => null, 'ipv6' => false, 'managed' => false, 'text' => '22/tcp ALLOW IN Anywhere'],
                ['verdict' => 'allow', 'ports' => [['start' => 443, 'end' => 443]], 'proto' => 'tcp', 'source' => null, 'ipv6' => false, 'managed' => false, 'text' => '443/tcp ALLOW IN Anywhere'],
                ['verdict' => 'allow', 'ports' => [['start' => 5432, 'end' => 5432]], 'proto' => 'tcp', 'source' => null, 'ipv6' => false, 'managed' => false, 'text' => '5432/tcp ALLOW IN Anywhere'],
                ['verdict' => 'allow', 'ports' => [['start' => 6004, 'end' => 6004]], 'proto' => 'tcp', 'source' => null, 'ipv6' => false, 'managed' => false, 'opaque' => true, 'text' => '6004/tcp on eth0 ALLOW IN Anywhere'],
            ],
            'ports' => [
                ['port' => 22, 'proto' => 'tcp', 'exposure' => 'open', 'because' => '22/tcp ALLOW IN Anywhere'],
                ['port' => 443, 'proto' => 'tcp', 'exposure' => 'open', 'because' => '443/tcp ALLOW IN Anywhere'],
                ['port' => 5432, 'proto' => 'tcp', 'exposure' => 'open', 'because' => '5432/tcp ALLOW IN Anywhere'],
                ['port' => 6379, 'proto' => 'tcp', 'exposure' => 'blocked', 'because' => 'ufw blocks anything no rule allows'],
                ['port' => 8080, 'proto' => 'tcp', 'exposure' => 'docker', 'because' => 'Published by a Docker container, so connections get in before the firewall can block them'],
            ],
            'unreadable' => 0,
            'notes' => [],
        ];
    }
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'inventory' => $inventory])->save();

    return [$workspace, $machine, 'https://'.$workspace->host()."/machines/{$machine->id}"];
}

function firewallJobsSent(): array
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn ($r) => str_ends_with($r->url(), '/commands'))->map(fn ($r) => $r['command']['job'])->values()->all();
}

test('the machine page shows what the internet can reach', function () {
    [$workspace, $machine, $url] = firewallMachine();

    $view = $this->actingAs($workspace->owner)->get("{$url}/ports")->assertOk()->viewData('page')['props']['machine']['firewall'];

    expect($view['supported'])->toBeTrue()
        ->and($view['unavailable'])->toBeNull()
        ->and(collect($view['ports'])->mapWithKeys(fn ($p) => [$p['port'] => $p['exposure']])->all())
        ->toBe([22 => 'open', 443 => 'open', 5432 => 'open', 6379 => 'blocked', 8080 => 'docker'])
        ->and(collect($view['ports'])->firstWhere('port', 5432)['what'])->toBe('PostgreSQL')
        ->and(collect($view['ports'])->firstWhere('port', 22)['ssh'])->toBeTrue()
        ->and($view['rules'][0]['ports'])->toBe('22')
        ->and($view['rules'][0]['opaque'])->toBeFalse()
        ->and($view['rules'][3]['opaque'])->toBeTrue();
});

test('a daemon that doesn’t read the firewall shows public ports as reachable', function () {
    [, $machine] = firewallMachine(false);

    $view = Firewall::view($machine);

    expect($view['supported'])->toBeFalse()
        ->and($view['unavailable'])->toContain('sudo serverosd update')
        ->and(collect($view['ports'])->pluck('exposure')->unique()->all())->toBe([null])
        ->and(collect(Firewall::exposedDatabases($machine))->pluck('port')->all())->toBe([5432, 6379]);
});

test('closing a port sends the job, follows it and records who did it', function () {
    [$workspace, $machine, $url] = firewallMachine();

    $jobId = $this->actingAs($workspace->owner)->postJson("{$url}/firewall", ['action' => 'close', 'port' => 5432])->assertAccepted()->json('jobId');

    expect(firewallJobsSent())->toBe([['type' => 'firewall_rule', 'action' => 'close', 'rule' => '5432/tcp']])
        ->and(Activity::query()->where('action', 'machine.firewall')->first()->meta)->toMatchArray(['action' => 'close', 'port' => '5432/tcp']);

    // A second change waits for the first.
    $this->postJson("{$url}/firewall", ['action' => 'close', 'port' => 6380])->assertStatus(409);

    $this->getJson("{$url}/firewall/{$jobId}")->assertOk()->assertJson(['state' => 'queued']);
});

test('the daemon’s fresh read of the firewall replaces the old one when the change is done', function () {
    [$workspace, $machine] = firewallMachine();
    $job = DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'firewall_rule', 'command' => [], 'state' => 'running', 'log' => []]);
    $after = $machine->inventory['firewall'];
    $after['ports'][2] = ['port' => 5432, 'proto' => 'tcp', 'exposure' => 'blocked', 'because' => 'ufw blocks anything no rule allows'];

    $this->withHeaders(['X-Gateway-Secret' => 'test-gateway-secret'])
        ->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', ['envelopes' => [[
            'v' => 1, 'kind' => 'job_update',
            'payload' => ['job_id' => $job->id, 'state' => 'succeeded', 'result' => ['exposure' => 'blocked', 'firewall' => $after]],
        ]]])
        ->assertOk();

    expect(collect(Firewall::view($machine->fresh())['ports'])->firstWhere('port', 5432)['exposure'])->toBe('blocked')
        ->and(collect(Firewall::exposedDatabases($machine->fresh()))->pluck('port')->all())->toBe([]);
});

test('SSH, Docker’s ports, and a firewall that’s off are refused before anything is sent', function () {
    [$workspace, , $url] = firewallMachine();
    $this->actingAs($workspace->owner);

    $this->postJson("{$url}/firewall", ['action' => 'close', 'port' => 22])->assertStatus(409)->assertJsonFragment(['error' => 'Port 22 is SSH. Closing it could lock everyone out of db-1, so ServerOS won’t.']);
    expect($this->postJson("{$url}/firewall", ['action' => 'close', 'port' => 8080])->assertStatus(409)->json('error'))->toContain('127.0.0.1');

    [$workspace, , $url] = firewallMachine(['backend' => 'ufw', 'installed' => true, 'active' => false, 'rules' => [], 'ports' => [], 'unreadable' => 0, 'notes' => []]);
    expect($this->actingAs($workspace->owner)->postJson("{$url}/firewall", ['action' => 'close', 'port' => 5432])->assertStatus(409)->json('error'))->toContain('turned off');

    [$workspace, , $url] = firewallMachine(false);
    expect($this->actingAs($workspace->owner)->postJson("{$url}/firewall", ['action' => 'open', 'port' => 80])->assertStatus(409)->json('error'))->toContain('serverosd update');

    expect(firewallJobsSent())->toBe([]);
});

test('only people who manage machines can change the firewall', function () {
    [$workspace, , $url] = firewallMachine();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    $this->actingAs($viewer)->postJson("{$url}/firewall", ['action' => 'open', 'port' => 80])->assertForbidden();
    $this->actingAs($workspace->owner)->postJson("{$url}/firewall", ['action' => 'open', 'port' => 70000])->assertUnprocessable();
    expect(firewallJobsSent())->toBe([]);
});

test('a database open to the internet needs attention, and one the firewall closes doesn’t', function () {
    [$workspace, $machine] = firewallMachine();

    $issue = collect(app(WorkspaceHealth::class)->issues($workspace))->firstWhere('id', "machine-{$machine->id}-exposed-5432");
    expect($issue['title'])->toBe('PostgreSQL on db-1 is open to the internet')
        ->and($issue['href'])->toBe("/machines/{$machine->id}/ports")
        // Ask AI can take it from there, and the machine's own page shows it too.
        ->and($issue['ask'])->toContain('PostgreSQL (5432) on db-1 can be reached from the internet')
        ->and(collect(app(WorkspaceHealth::class)->forMachine($machine))->pluck('id'))->toContain("machine-{$machine->id}-exposed-5432");

    $scan = collect((new SecurityScan)->report($machine, null)['checks'])->firstWhere('key', 'ports');
    expect($scan['state'])->toBe('issue')
        ->and($scan['summary'])->toBe('4 open to the internet · 1 closed by the firewall · 1 local only')
        ->and(collect($scan['items'])->pluck('text'))->not->toContain('6379/tcp is open to the internet: Redis should usually only listen locally');
});

test('only addresses nothing outside can reach count as internal: loopback, link-local, Tailscale and Docker’s own bridges', function (string $address, bool $public) {
    expect(Firewall::isPublic($address, ['172.18.0.1', '172.19.0.1']))->toBe($public);
})->with([
    ['0.0.0.0', true], ['::', true], ['[::]', true], ['203.0.113.10', true], ['2a01:4f8::1', true],
    // Private, but NAT'd to the public IP on AWS, GCP and Azure.
    ['172.31.5.10', true], ['10.0.0.5', true], ['192.168.1.2', true], ['fd00::1', true],
    // A dual-stack socket's view of an IPv4 address.
    ['::ffff:203.0.113.10', true], ['::ffff:127.0.0.1', false],
    ['127.0.0.1', false], ['::1', false], ['localhost', false], ['169.254.1.1', false], ['fe80::1%eth0', false], ['100.100.1.1', false],
    // Docker publishing on its bridge so other containers reach it.
    ['172.18.0.1', false], ['172.19.0.1', false],
]);

test('a database Docker publishes only on its bridge isn’t raised as open to the internet', function () {
    [$workspace, $machine] = firewallMachine(false);
    $inventory = $machine->inventory;
    $inventory['listeners'] = [
        ['proto' => 'tcp', 'address' => '127.0.0.1', 'port' => 5432, 'pid' => 1, 'exe' => '/usr/bin/docker-proxy', 'user' => 'root'],
        ['proto' => 'tcp', 'address' => '172.18.0.1', 'port' => 5432, 'pid' => 2, 'exe' => '/usr/bin/docker-proxy', 'user' => 'root'],
        ['proto' => 'tcp', 'address' => '172.18.0.1', 'port' => 6379, 'pid' => 3, 'exe' => '/usr/bin/docker-proxy', 'user' => 'root'],
    ];
    $machine->forceFill(['inventory' => $inventory, 'facts' => ['interfaces' => [['name' => 'docker0', 'addresses' => ['172.18.0.1/16']], ['name' => 'eth0', 'addresses' => ['10.0.0.4/24']]]]])->save();

    expect(Firewall::view($machine)['ports'])->toBe([])
        ->and(Firewall::exposedDatabases($machine))->toBe([])
        ->and(collect(app(WorkspaceHealth::class)->issues($workspace))->pluck('id')->filter(fn ($id) => str_contains($id, 'exposed')))->toBeEmpty();
});

test('a database Docker publishes on another port is still recognised', function () {
    [$workspace, $machine] = firewallMachine(false);
    $inventory = $machine->inventory;
    $inventory['listeners'] = [['proto' => 'tcp', 'address' => '0.0.0.0', 'port' => 6380, 'pid' => 1, 'exe' => '/usr/bin/docker-proxy', 'user' => 'root',
        'cmdline' => '/usr/bin/docker-proxy -proto tcp -host-ip 0.0.0.0 -host-port 6380 -container-ip 172.17.0.2 -container-port 6379 -use-listen-fd']];
    $machine->forceFill(['inventory' => $inventory])->save();

    expect(Firewall::exposedDatabases($machine))->toBe([['port' => 6380, 'proto' => 'tcp', 'what' => 'Redis', 'exposure' => null]]);
});

test('the assistant can see the firewall and close a port once the person agrees', function () {
    [$workspace, $machine] = firewallMachine();
    $key = mcpKey($workspace);

    $firewall = mcpCall($workspace, $key, 'get_firewall', ['machine' => 'db-1'])['data'];
    expect(collect($firewall['firewall']['ports'])->firstWhere('port', 5432)['exposure'])->toBe('open');

    $unconfirmed = mcpRequest($workspace, $key, 'tools/call', ['name' => 'close_port', 'arguments' => ['machine' => 'db-1', 'port' => 5432]])->json('result');
    expect($unconfirmed['isError'])->toBeTrue()->and(firewallJobsSent())->toBe([]);

    $ssh = mcpRequest($workspace, $key, 'tools/call', ['name' => 'close_port', 'arguments' => ['machine' => 'db-1', 'port' => 22, 'confirm' => 'db-1']])->json('result');
    expect($ssh['isError'])->toBeTrue()->and($ssh['content'][0]['text'])->toContain('SSH');

    $closed = mcpCall($workspace, $key, 'close_port', ['machine' => 'db-1', 'port' => 5432, 'confirm' => 'db-1'])['data'];
    expect($closed['port'])->toBe('5432/tcp')
        ->and(firewallJobsSent())->toBe([['type' => 'firewall_rule', 'action' => 'close', 'rule' => '5432/tcp']]);
});

test('a change that fails its check asks the machine for a fresh scan', function () {
    [, $machine] = firewallMachine();
    $job = DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'firewall_rule', 'command' => [], 'state' => 'running', 'log' => []]);

    $this->withHeaders(['X-Gateway-Secret' => 'test-gateway-secret'])
        ->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', ['envelopes' => [[
            'v' => 1, 'kind' => 'job_update',
            'payload' => ['job_id' => $job->id, 'state' => 'failed', 'error' => ['code' => 'firewall', 'message' => '8003/tcp is still blocked after the change']],
        ]]])
        ->assertOk();

    expect(collect(firewallJobsSent())->pluck('type')->all())->toBe(['discover']);
});
