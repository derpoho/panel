<?php

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Machines\PackageUpdates;
use App\Support\Machines\StaleServices;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** @return array{0: Workspace, 1: Machine, 2: string} */
function staleMachine(): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()])->save();

    return [$workspace, $machine, 'https://'.$workspace->host()."/machines/{$machine->id}"];
}

/** @param  array<string, mixed>  $restarts */
function finishedCheck(Machine $machine, array $restarts, bool $rebootFile = true): DaemonJob
{
    return DaemonJob::query()->create([
        'id' => (string) Str::uuid(),
        'machine_id' => $machine->id,
        'kind' => 'package_updates',
        'state' => 'succeeded',
        'command' => ['job' => ['type' => 'package_updates', 'apply' => false, 'security_only' => false]],
        'result' => ['manager' => 'apt', 'packages' => [], 'reboot_required' => $rebootFile, 'applied' => false, 'restarts' => $restarts],
        'finished_at' => now(),
    ]);
}

/** What the daemon reports after an openssl and libc update. */
function afterLibcUpdate(): array
{
    return [
        'services' => [
            ['unit' => 'nginx.service', 'processes' => 9, 'files' => ['/usr/lib/x86_64-linux-gnu/libssl.so.3'], 'disruptive' => false],
            ['unit' => 'cron.service', 'processes' => 1, 'files' => ['/usr/lib/x86_64-linux-gnu/libc.so.6'], 'disruptive' => false],
            ['unit' => 'docker.service', 'processes' => 1, 'files' => ['/usr/lib/x86_64-linux-gnu/libc.so.6'], 'disruptive' => true],
            ['unit' => 'evil;rm -rf.service', 'processes' => 1, 'files' => [], 'disruptive' => false],
        ],
        'systemd' => true,
        'reboot' => [],
        'later' => ['dbus.service', 'systemd-logind.service'],
        'kernel' => '6.8.0-48-generic',
        'newest_kernel' => null,
    ];
}

test('a libc update asks for service restarts, not a reboot, when the daemon says which', function () {
    [$workspace, $machine, $url] = staleMachine();

    PackageUpdates::ingest(finishedCheck($machine, afterLibcUpdate()));
    $machine->refresh();

    expect($machine->reboot_required)->toBeTrue()
        ->and($machine->needsReboot())->toBeFalse()
        ->and(array_column($machine->restarts['services'], 'unit'))->toBe(['nginx.service', 'cron.service', 'docker.service'])
        ->and($machine->restarts['later'])->toBe(['dbus.service', 'systemd-logind.service']);

    $this->actingAs($workspace->owner)->getJson("{$url}/updates")->assertOk()
        ->assertJsonPath('rebootRequired', false)
        ->assertJsonPath('restarts.services.0.unit', 'nginx.service')
        ->assertJsonPath('restarts.systemd', true);

    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/updates')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summary.rebootRequired', 0)
            ->where('summary.restartServices', 1)
            ->where('machines.0.staleServices', 3));
    // nginx, cron and systemd: Docker waits until it's asked for by name.
});

test('a newer kernel still needs a reboot, and an older daemon keeps its own answer', function () {
    [, $machine] = staleMachine();

    PackageUpdates::ingest(finishedCheck($machine, [
        'services' => [], 'systemd' => false, 'later' => [],
        'reboot' => [['kind' => 'kernel', 'detail' => '6.8.0-48-generic is installed, 6.8.0-45-generic is running']],
        'kernel' => '6.8.0-45-generic', 'newest_kernel' => '6.8.0-48-generic',
    ]));
    expect($machine->refresh()->needsReboot())->toBeTrue()
        ->and($machine->restarts['newestKernel'])->toBe('6.8.0-48-generic');

    $old = Machine::factory()->for($machine->workspace)->create(['reboot_required' => true]);
    expect($old->needsReboot())->toBeTrue()->and($old->restarts)->toBeNull();
});

test('restarting sends the stale services, leaves Docker out unless named, and records it', function () {
    [$workspace, $machine, $url] = staleMachine();
    PackageUpdates::ingest(finishedCheck($machine, afterLibcUpdate()));

    $jobId = $this->actingAs($workspace->owner)->postJson("{$url}/updates/restart")->assertAccepted()->json('jobId');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/commands')
        && $r['command']['job'] == ['type' => 'restart_stale', 'units' => ['nginx.service', 'cron.service'], 'systemd' => true]);
    expect(Activity::query()->where('action', 'machine.restart_services')->sole()->meta)->toMatchArray(['services' => ['nginx', 'cron']]);

    $this->actingAs($workspace->owner)->postJson("{$url}/updates/restart", ['include' => ['docker.service']])->assertAccepted();
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/commands')
        && ($r['command']['job']['units'] ?? null) === ['nginx.service', 'cron.service', 'docker.service']);

    DaemonJob::query()->whereKey($jobId)->update(['state' => 'succeeded', 'result' => json_encode([
        'restarted' => [
            ['unit' => 'systemd', 'ok' => true, 'skipped' => false, 'message' => null],
            ['unit' => 'nginx.service', 'ok' => true, 'skipped' => false, 'message' => null],
            ['unit' => 'cron.service', 'ok' => false, 'skipped' => false, 'message' => 'restarted but isn’t running: failed'],
        ],
        'restarts' => ['services' => [['unit' => 'cron.service', 'processes' => 1, 'files' => [], 'disruptive' => false]], 'systemd' => false, 'reboot' => [], 'later' => []],
    ])]);
    $this->actingAs($workspace->owner)->getJson("{$url}/updates/{$jobId}")->assertOk()
        ->assertJsonPath('restarts.failed', [['unit' => 'cron.service', 'message' => 'restarted but isn’t running: failed']])
        ->assertJsonPath('restarts.after.services.0.unit', 'cron.service');

    StaleServices::ingest(DaemonJob::query()->findOrFail($jobId));
    expect(array_column($machine->refresh()->restarts['services'], 'unit'))->toBe(['cron.service'])
        ->and($machine->restarts['systemd'])->toBeFalse();
});

test('nothing to restart, an older daemon or a viewer gets no restart', function () {
    [$workspace, $machine, $url] = staleMachine();

    $this->actingAs($workspace->owner)->postJson("{$url}/updates/restart")->assertStatus(409);

    PackageUpdates::ingest(finishedCheck($machine, ['services' => [], 'systemd' => false, 'reboot' => [], 'later' => ['dbus.service']]));
    $this->actingAs($workspace->owner)->postJson("{$url}/updates/restart")->assertStatus(409);

    PackageUpdates::ingest(finishedCheck($machine, afterLibcUpdate()));
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)->postJson("{$url}/updates/restart")->assertForbidden();

    $this->actingAs($workspace->owner)->postJson("{$url}/updates/restart", ['include' => ['--force; reboot']])->assertUnprocessable();
    Http::assertNotSent(fn ($r) => ($r['command']['job']['type'] ?? null) === 'restart_stale');
});
