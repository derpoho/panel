<?php

use App\Enums\Plan;
use App\Jobs\SendNtfy;
use App\Models\Activity;
use App\Models\AdminAction;
use App\Models\DaemonEnrolment;
use App\Models\ImpersonationSession;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function connectUrl(Workspace $workspace): string
{
    return 'https://'.$workspace->host().'/machines/connect';
}

function daemonCsr(): string
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1', 'private_key_bits' => 384]);
    $csr = openssl_csr_new(['commonName' => 'pending'], $key);
    openssl_csr_export($csr, $pem);

    return $pem;
}

test('a manager gets a one-time expiring daemon command and a viewer cannot', function () {
    config()->set('daemon.install_ready', true);
    $workspace = Workspace::factory()->create();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    $this->actingAs($viewer)->postJson(connectUrl($workspace))->assertForbidden();
    $this->actingAs($workspace->owner)->postJson(connectUrl($workspace))
        ->assertOk()->assertJsonStructure(['command', 'expiresAt', 'enrolmentId']);

    $enrolment = DaemonEnrolment::query()->firstOrFail();
    expect($enrolment->token_hash)->toHaveLength(64)
        ->and($enrolment->expires_at->between(now()->addMinutes(14), now()->addMinutes(16)))->toBeTrue();
});

test('daemon enrolment, gateway handshake and port inventory work together', function () {
    config()->set('daemon.install_ready', true);
    config()->set('services.ntfy.topic', 'serveros-team');
    Queue::fake([SendNtfy::class]);
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $dir = sys_get_temp_dir().'/serveros-ca-test-'.bin2hex(random_bytes(5));
    config()->set('daemon.ca_cert', $dir.'/ca.crt');
    config()->set('daemon.ca_key', $dir.'/ca.key');
    config()->set('daemon.gateway_host', 'gateway.serveros.test');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Artisan::call('daemon:ca', ['action' => 'init']);

    try {
        $command = $this->actingAs($workspace->owner)->postJson(connectUrl($workspace))->assertOk()->json('command');
        preg_match('/--token=\x27([a-f0-9]{64})\x27/', $command, $match);
        $token = $match[1] ?? null;
        expect($token)->not->toBeNull();

        $facts = ['hostname' => 'box-1', 'os' => 'ubuntu', 'interfaces' => [],
            'hosting' => ['provider' => 'Hetzner', 'region' => 'eu-central', 'zone' => 'hel1-dc2', 'source' => 'instance_metadata']];
        // The daemon enrols on its own, not as the signed-in owner.
        $this->app['auth']->forgetGuards();
        $enrol = $this->postJson('/api/daemon/enrol', [
            'token' => $token, 'csr' => daemonCsr(), 'daemon_version' => 'v0.1.0',
            'protocol_majors' => [1], 'facts' => $facts,
        ])->assertCreated()->assertJsonStructure(['machine_id', 'certificate', 'ca', 'panel_host']);

        $uid = $enrol->json('machine_id');
        $machine = Machine::query()->where('uid', $uid)->firstOrFail();
        expect($machine->provider)->toBe('Hetzner')
            ->and($machine->facts['hosting']['zone'])->toBe('hel1-dc2');
        // Credited to whoever made the join command, not to nobody; the
        // daemon's request says nothing about where they were.
        $joined = Activity::query()->where('action', 'machine.connect')->sole();
        expect($joined->user_id)->toBe($workspace->owner->id)
            ->and(ActivityLine::sentence($joined))->toBe($workspace->owner->name.' connected box-1')
            ->and($joined->ip)->toBeNull()
            ->and($joined->source)->toBe('machine');
        Queue::assertPushed(SendNtfy::class, fn (SendNtfy $push) => $push->title === 'Machine connected'
            && $push->message === 'A machine connected on Hetzner');
        $certificate = openssl_x509_parse($enrol->json('certificate'));
        expect(strtolower(ltrim($certificate['serialNumberHex'], '0')))->toBe($machine->certificate_serial);
        $this->postJson('/api/daemon/enrol', [
            'token' => $token, 'csr' => daemonCsr(), 'daemon_version' => 'v0.1.0',
            'protocol_majors' => [1], 'facts' => $facts,
        ])->assertStatus(409)->assertJsonPath('code', 'token_used');

        $headers = ['X-Gateway-Secret' => 'test-gateway-secret'];
        $this->postJson('/api/gateway/hello', [
            'serial' => $machine->certificate_serial,
            'hello' => ['machine_id' => $uid, 'daemon_version' => 'v0.1.0', 'supported_majors' => [1], 'facts' => $facts],
        ], $headers)->assertOk()->assertJsonPath('ack.major', 1);

        $report = ['scanned_at' => now()->timestamp, 'complete' => true,
            'services' => [['key' => 'systemd:nginx.service', 'name' => 'nginx', 'ports' => [80]]],
            'listeners' => [['proto' => 'tcp', 'address' => '0.0.0.0', 'port' => 80, 'pid' => 11, 'exe' => '/usr/sbin/nginx']],
            'unknown' => [], 'warnings' => []];
        $this->postJson("/api/gateway/machines/{$uid}/ingest", [
            'envelopes' => [
                ['v' => 1, 'kind' => 'inventory', 'payload' => $report],
                ['v' => 1, 'kind' => 'facts', 'payload' => array_replace_recursive($facts, ['hosting' => ['provider' => 'AWS', 'region' => 'eu-west-2', 'zone' => 'eu-west-2a']])],
            ],
        ], $headers)->assertOk();
        expect($machine->fresh()->services_count)->toBe(1)
            ->and($machine->fresh()->provider)->toBe('AWS')
            ->and($machine->fresh()->inventory['listeners'][0]['port'])->toBe(80);
    } finally {
        @unlink($dir.'/ca.crt');
        @unlink($dir.'/ca.key');
        @rmdir($dir);
    }
});

test('a machine keeps the name someone gave it when its daemon reconnects, and follows its hostname until then', function () {
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    $machine = Machine::factory()->create(['name' => 'box-1']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'certificate_serial' => 'abc123', 'facts' => ['hostname' => 'box-1']])->save();
    $hello = fn (string $hostname) => $this->postJson('/api/gateway/hello', [
        'serial' => 'abc123',
        'hello' => ['machine_id' => $machine->uid, 'daemon_version' => 'v0.1.0', 'supported_majors' => [1], 'facts' => ['hostname' => $hostname]],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();

    // Still named after its hostname: it follows a new one.
    $hello('box-2');
    expect($machine->fresh()->name)->toBe('box-2');

    // Renamed by someone: that name stays, whatever the hostname is.
    $machine->fresh()->update(['name' => 'Shop database']);
    $hello('box-2');
    $hello('box-3');
    expect($machine->fresh()->name)->toBe('Shop database');
});

test('a machine joining with a command staff left reads as ServerOS Support, with no IP on either row', function () {
    config()->set('daemon.install_ready', true);
    Queue::fake([SendNtfy::class]);
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $boss = User::factory()->admin()->create(['name' => 'Boss']);
    $dir = sys_get_temp_dir().'/serveros-ca-test-'.bin2hex(random_bytes(5));
    config()->set('daemon.ca_cert', $dir.'/ca.crt');
    config()->set('daemon.ca_key', $dir.'/ca.key');
    config()->set('daemon.gateway_host', 'gateway.serveros.test');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Artisan::call('daemon:ca', ['action' => 'init']);

    try {
        $this->actingAs($boss)->post(viewAsUrl($workspace->owner), ['reason' => 'Helping them connect']);
        $command = $this->postJson(connectUrl($workspace))->assertOk()->json('command');
        preg_match('/--token=\x27([a-f0-9]{64})\x27/', $command, $match);
        $session = ImpersonationSession::query()->sole();
        expect(DaemonEnrolment::query()->sole()->impersonation_session_id)->toBe($session->id);

        // The daemon enrols from the server, in a request of its own.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/daemon/enrol', [
            'token' => $match[1], 'csr' => daemonCsr(), 'daemon_version' => 'v0.1.0',
            'protocol_majors' => [1], 'facts' => ['hostname' => 'box-1', 'os' => 'ubuntu', 'interfaces' => []],
        ])->assertCreated();

        $joined = Activity::query()->where('action', 'machine.connect')->sole();
        expect($joined->user_id)->toBeNull()
            ->and($joined->impersonation_session_id)->toBe($session->id)
            ->and(ActivityLine::sentence($joined))->toBe('ServerOS Support connected box-1')
            ->and($joined->ip)->toBeNull()
            ->and($joined->user_agent)->toBeNull();
        // Our copy names the admin, but not the server's address as theirs.
        // (The join command itself is in the log too, from the View as backstop.)
        $copy = AdminAction::query()->where('action', 'viewas.write')->get()->firstWhere('meta.action', 'machine.connect');
        expect($copy->admin_id)->toBe($boss->id)
            ->and($copy->impersonation_session_id)->toBe($session->id)
            ->and($copy->ip)->toBeNull()
            ->and($copy->user_agent)->toBeNull()
            ->and($copy->meta)->toMatchArray(['action' => 'machine.connect', 'as' => $workspace->owner->name])
            ->and($copy->meta['what'])->toContain('box-1');
    } finally {
        @unlink($dir.'/ca.crt');
        @unlink($dir.'/ca.key');
        @rmdir($dir);
    }
});

test('a fresh command can replace your unused ones, so they stop holding the plan’s machine places', function () {
    config()->set('daemon.install_ready', true);
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();

    $first = $this->actingAs($workspace->owner)->postJson(connectUrl($workspace))->assertOk()->json('enrolmentId');

    // The welcome flow, opened again: a new command in place of the old one.
    $this->actingAs($workspace->owner)->postJson(connectUrl($workspace), ['replace' => true])->assertOk();
    expect(DaemonEnrolment::query()->find($first)->expires_at->isFuture())->toBeFalse()
        ->and(DaemonEnrolment::query()->where('expires_at', '>', now())->count())->toBe(1);
});

test('at the machine limit, a new command takes the place of your own unused one, as after a failed install', function () {
    config()->set('daemon.install_ready', true);
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $limit = $workspace->machineLimit();
    Machine::factory()->count($limit - 1)->for($workspace)->create();

    $first = $this->actingAs($workspace->owner)->postJson(connectUrl($workspace))->assertOk()->json('enrolmentId');
    // The install didn't finish; trying again isn't refused.
    $second = $this->actingAs($workspace->owner)->postJson(connectUrl($workspace))->assertOk()->json('enrolmentId');

    expect(DaemonEnrolment::query()->find($first)->expires_at->isFuture())->toBeFalse()
        ->and(DaemonEnrolment::query()->find($second)->expires_at->isFuture())->toBeTrue();

    // Someone else's unused command still holds its place.
    $teammate = User::factory()->create();
    $workspace->members()->attach($teammate, ['role' => 'admin']);
    $this->actingAs($teammate)->postJson(connectUrl($workspace))->assertStatus(409);
    expect(DaemonEnrolment::query()->find($second)->expires_at->isFuture())->toBeTrue();
});
