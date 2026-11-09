<?php

use App\Enums\Plan;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\MachinePackageUpdate;
use App\Models\PackageRollout;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Machines\PackageRollouts;
use App\Support\Machines\PackageUpdates;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** A Business workspace whose gateway takes every job. */
function pkgWorkspace(): Workspace
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    return Workspace::factory()->onPlan(Plan::Business)->create();
}

/** An online machine on a daemon that knows exact versions and holds. */
function pkgMachine(Workspace $workspace, string $name, array $attributes = []): Machine
{
    $machine = Machine::factory()->for($workspace)->create(['name' => $name]);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'connected_at' => now()->subDay(),
        'daemon_version' => 'v0.1.1-pre.26',
        'os_release' => ['id' => 'ubuntu', 'version_id' => '24.04', 'pretty_name' => 'Ubuntu 24.04.1 LTS'],
        ...$attributes,
    ])->save();

    return $machine;
}

/** A finished check that found these packages: [name => [installed, candidate, security]]. */
function pkgSnapshot(Machine $machine, array $packages, array $extra = []): DaemonJob
{
    $job = DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'package_updates', 'state' => 'succeeded', 'log' => [],
        'command' => ['job' => ['type' => 'package_updates', 'apply' => false, 'security_only' => false]],
        'result' => ['manager' => 'apt', 'upgradable' => [], 'security' => [], 'reboot_required' => false, 'applied' => false, 'held' => [],
            'packages' => collect($packages)->map(fn (array $p, string $name) => ['name' => $name, 'installed' => $p[0], 'candidate' => $p[1], 'security' => $p[2] ?? false, 'arch' => 'amd64'])->values()->all(),
            ...$extra],
        'finished_at' => now(),
    ]);
    PackageUpdates::ingest($job);

    return $job;
}

/** The daemon reports a job finished, through the gateway. */
function pkgFinish(Machine $machine, string $jobId, string $state = 'succeeded', array $result = [], ?array $error = null): void
{
    test()->withHeaders(['X-Gateway-Secret' => 'test-gateway-secret'])
        ->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', ['envelopes' => [[
            'v' => 1, 'kind' => 'job_update',
            'payload' => array_filter(['job_id' => $jobId, 'state' => $state, 'result' => $result ?: null, 'error' => $error]),
        ]]])
        ->assertOk();
}

/** Every job sent to the gateway, in order. */
function pkgSent(): array
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn ($r) => str_ends_with($r->url(), '/commands'))
        ->map(fn ($r) => $r['command']['job'])->values()->all();
}

function pkgUrl(Workspace $workspace, string $path): string
{
    return 'https://'.$workspace->host().$path;
}

/** The newest job of this kind on the machine. */
function pkgJob(Machine $machine, string $kind): DaemonJob
{
    return DaemonJob::query()->where('machine_id', $machine->id)->where('kind', $kind)->get()->last()
        ?? throw new RuntimeException("No {$kind} job on {$machine->name}");
}

/** Run the rollout ticker, as the scheduler does every minute. */
function pkgTick(): void
{
    app(PackageRollouts::class)->tick();
}

test('a check from a new daemon is kept in full, with held packages and the OS', function () {
    $workspace = pkgWorkspace();
    $machine = pkgMachine($workspace, 'web-1');

    $jobId = test()->actingAs($workspace->owner)->postJson(pkgUrl($workspace, "/machines/{$machine->id}/updates/check"))->assertAccepted()->json('jobId');
    pkgFinish($machine, $jobId, result: [
        'manager' => 'apt', 'upgradable' => [], 'security' => [], 'reboot_required' => true, 'applied' => false,
        'packages' => [
            ['name' => 'openssl', 'installed' => '3.0.13-0ubuntu3.4', 'candidate' => '3.0.13-0ubuntu3.5', 'security' => true, 'arch' => 'amd64'],
            ['name' => 'curl', 'installed' => '8.5.0-2ubuntu10.5', 'candidate' => '8.5.0-2ubuntu10.6', 'security' => false, 'arch' => null],
            ['name' => '', 'candidate' => 'x'],
        ],
        'held' => ['nginx', 'docker-ce'],
        'os' => ['id' => 'ubuntu', 'version_id' => '24.04', 'pretty_name' => 'Ubuntu 24.04.1 LTS'],
    ]);

    $machine->refresh();
    expect($machine->updates_count)->toBe(2)
        ->and($machine->security_updates_count)->toBe(1)
        ->and($machine->reboot_required)->toBeTrue()
        ->and($machine->updates_complete)->toBeTrue()
        ->and($machine->updates_checked_at)->not->toBeNull()
        ->and($machine->held_packages)->toBe(['docker-ce', 'nginx'])
        ->and($machine->os_release['pretty_name'])->toBe('Ubuntu 24.04.1 LTS')
        ->and(MachinePackageUpdate::query()->where('name', 'openssl')->first()->only(['installed', 'candidate', 'security', 'arch']))
        ->toBe(['installed' => '3.0.13-0ubuntu3.4', 'candidate' => '3.0.13-0ubuntu3.5', 'security' => true, 'arch' => 'amd64']);

    // The dialog paints the kept list at once.
    test()->getJson(pkgUrl($workspace, "/machines/{$machine->id}/updates"))->assertOk()
        ->assertJsonPath('packages.0.name', 'openssl')
        ->assertJsonPath('held', ['docker-ce', 'nginx'])
        ->assertJsonPath('rebootRequired', true);

    // A later check replaces the lot.
    pkgSnapshot($machine, ['curl' => ['8.5.0-2ubuntu10.5', '8.5.0-2ubuntu10.6']]);
    expect(MachinePackageUpdate::query()->where('machine_id', $machine->id)->pluck('name')->all())->toBe(['curl'])
        ->and($machine->fresh()->security_updates_count)->toBe(0);
});

test('an older daemon’s lines are parsed, and the list is marked as maybe incomplete', function () {
    $workspace = pkgWorkspace();
    $machine = pkgMachine($workspace, 'web-1', ['daemon_version' => 'v0.1.1-pre.20']);
    $line = 'openssl/noble-updates,noble-security 3.0.13-0ubuntu3.5 amd64 [upgradable from: 3.0.13-0ubuntu3.4]';
    $job = DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'package_updates', 'state' => 'running', 'log' => [],
        'command' => ['job' => ['type' => 'package_updates', 'apply' => false, 'security_only' => false]]]);

    pkgFinish($machine, $job->id, result: ['manager' => 'apt', 'upgradable' => [$line, 'curl/noble-updates 8.5.0-2ubuntu10.6 amd64 [upgradable from: 8.5.0-2ubuntu10.5]'], 'security' => [$line], 'reboot_required' => false, 'applied' => false]);

    $machine->refresh();
    expect($machine->updates_count)->toBe(2)
        ->and($machine->security_updates_count)->toBe(1)
        ->and($machine->updates_complete)->toBeFalse()
        ->and(MachinePackageUpdate::query()->where('name', 'openssl')->first()->only(['installed', 'candidate', 'security', 'arch']))
        ->toBe(['installed' => '3.0.13-0ubuntu3.4', 'candidate' => '3.0.13-0ubuntu3.5', 'security' => true, 'arch' => 'amd64']);
});

test('installs mark what they installed as done, then a fresh check follows', function () {
    $workspace = pkgWorkspace();
    $machine = pkgMachine($workspace, 'web-1');
    $snapshot = ['openssl' => ['3.0.13-0ubuntu3.4', '3.0.13-0ubuntu3.5', true], 'curl' => ['8.5.0-2ubuntu10.5', '8.5.0-2ubuntu10.6'], 'vim' => ['2:9.1.0016-1ubuntu7.2', '2:9.1.0016-1ubuntu7.4'], 'libc6' => ['2.39-0ubuntu8.3', '2.39-0ubuntu8.4', true]];
    pkgSnapshot($machine, $snapshot);
    $names = fn () => MachinePackageUpdate::query()->where('machine_id', $machine->id)->orderBy('name')->pluck('name')->all();

    // Chosen packages: gone from the list, and a check is sent.
    test()->actingAs($workspace->owner)->postJson(pkgUrl($workspace, "/machines/{$machine->id}/updates"), ['security_only' => false, 'packages' => ['curl', 'libc6:amd64']])->assertAccepted();
    pkgFinish($machine, pkgJob($machine, 'package_install')->id, result: ['manager' => 'apt', 'applied' => true, 'reboot_required' => true, 'upgradable' => [], 'security' => []]);
    expect($names())->toBe(['openssl', 'vim'])
        ->and($machine->fresh()->reboot_required)->toBeTrue()
        ->and($machine->fresh()->updates_count)->toBe(2)
        ->and(collect(pkgSent())->last())->toBe(['type' => 'package_updates', 'apply' => false, 'security_only' => false]);

    // An exact version: the newest leaves the list; an older one leaves the newer still waiting.
    pkgSnapshot($machine, $snapshot);
    $exact = DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'package_install_versions', 'state' => 'running', 'log' => [],
        'command' => ['job' => ['type' => 'package_install_versions', 'packages' => [['name' => 'openssl', 'version' => '3.0.13-0ubuntu3.5'], ['name' => 'vim', 'version' => '2:9.1.0016-1ubuntu7.3']], 'allow_downgrade' => false]]]);
    pkgFinish($machine, $exact->id, result: ['manager' => 'apt', 'applied' => true, 'installed' => [['name' => 'openssl', 'version' => '3.0.13-0ubuntu3.5'], ['name' => 'vim', 'version' => '2:9.1.0016-1ubuntu7.3']], 'reboot_required' => false, 'packages' => [], 'held' => ['nginx']]);
    expect($names())->toBe(['curl', 'libc6', 'vim'])
        ->and(MachinePackageUpdate::query()->where('name', 'vim')->value('installed'))->toBe('2:9.1.0016-1ubuntu7.3')
        ->and($machine->fresh()->held_packages)->toBe(['nginx'])
        ->and($machine->fresh()->reboot_required)->toBeFalse();

    // Security only, then everything.
    pkgSnapshot($machine, $snapshot);
    $security = DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'package_updates', 'state' => 'running', 'log' => [],
        'command' => ['job' => ['type' => 'package_updates', 'apply' => true, 'security_only' => true]]]);
    pkgFinish($machine, $security->id, result: ['manager' => 'apt', 'applied' => true, 'upgradable' => [], 'security' => []]);
    expect($names())->toBe(['curl', 'vim']);

    $all = DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'package_updates', 'state' => 'running', 'log' => [],
        'command' => ['job' => ['type' => 'package_updates', 'apply' => true, 'security_only' => false]]]);
    pkgFinish($machine, $all->id, result: ['manager' => 'apt', 'applied' => true, 'upgradable' => [], 'security' => []]);
    expect($names())->toBe([])->and($machine->fresh()->updates_count)->toBe(0);

    // A failed install changes nothing.
    pkgSnapshot($machine, $snapshot);
    $failed = DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'package_install', 'state' => 'running', 'log' => [],
        'command' => ['job' => ['type' => 'package_install', 'packages' => ['curl']]]]);
    pkgFinish($machine, $failed->id, 'failed', error: ['message' => 'dpkg was interrupted']);
    expect($names())->toHaveCount(4);
});

test('the daily check asks online machines not checked lately, a limited number at a time', function () {
    $workspace = pkgWorkspace();
    $never = pkgMachine($workspace, 'never');
    $stale = pkgMachine($workspace, 'stale', ['updates_checked_at' => now()->subDay()]);
    pkgMachine($workspace, 'fresh', ['updates_checked_at' => now()->subHours(2)]);
    pkgMachine($workspace, 'offline', ['last_seen_at' => now()->subMinutes(5)]);
    $busy = pkgMachine($workspace, 'busy');
    DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $busy->id, 'kind' => 'package_install', 'state' => 'running', 'log' => [], 'command' => ['job' => ['type' => 'package_install', 'packages' => ['curl']]], 'created_at' => now()->subHours(8)]);
    $tried = pkgMachine($workspace, 'tried');
    DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $tried->id, 'kind' => 'package_updates', 'state' => 'failed', 'log' => [], 'command' => ['job' => ['type' => 'package_updates', 'apply' => false]], 'created_at' => now()->subHour()]);

    $this->artisan('updates:check-due', ['--limit' => 1])->assertSuccessful();
    expect(DaemonJob::query()->where('kind', 'package_updates')->where('state', 'queued')->pluck('machine_id')->all())->toBe([$never->id]);

    $this->artisan('updates:check-due')->assertSuccessful();
    expect(DaemonJob::query()->where('kind', 'package_updates')->where('state', 'queued')->pluck('machine_id')->sort()->values()->all())->toBe([$never->id, $stale->id])
        ->and(pkgSent())->each->toBe(['type' => 'package_updates', 'apply' => false, 'security_only' => false]);

    // Nothing is sent while commands are off.
    config()->set('daemon.commands_enabled', false);
    $stale->forceFill(['updates_checked_at' => now()->subDays(2)])->save();
    DaemonJob::query()->delete();
    $this->artisan('updates:check-due')->assertSuccessful();
    expect(DaemonJob::query()->count())->toBe(0);
});

test('a rollout goes a batch at a time and finishes', function () {
    $workspace = pkgWorkspace();
    $machines = collect(['web-1', 'web-2', 'web-3'])->map(fn (string $name) => pkgMachine($workspace, $name));
    $machines->each(fn (Machine $m) => pkgSnapshot($m, ['openssl' => ['3.0.13-0ubuntu3.4', '3.0.13-0ubuntu3.5', true], 'curl' => ['8.5.0-2ubuntu10.5', '8.5.0-2ubuntu10.6']]));

    $this->actingAs($workspace->owner)->postJson(pkgUrl($workspace, '/updates/rollouts'), [
        'packages' => [['name' => 'openssl'], ['name' => 'nginx']], 'mode' => 'latest', 'batch_size' => 2, 'stop_on_failure' => true,
    ])->assertCreated()->assertJsonPath('rollout.state', 'running')->assertJsonPath('rollout.total', 3);

    $rollout = PackageRollout::query()->sole();
    // Only what's waiting on each machine is named: nginx isn't.
    expect(pkgSent())->toBe([['type' => 'package_install', 'packages' => ['openssl']], ['type' => 'package_install', 'packages' => ['openssl']]])
        ->and($rollout->name)->toBe('openssl and nginx')
        ->and(Activity::query()->where('action', 'updates.rollout')->value('meta'))->toMatchArray(['machines' => 3, 'packages' => 2, 'batch' => 2]);

    pkgTick();
    expect(DaemonJob::query()->where('kind', 'package_install')->count())->toBe(2);

    pkgFinish($machines[0], pkgJob($machines[0], 'package_install')->id, result: ['manager' => 'apt', 'applied' => true, 'reboot_required' => false]);
    pkgTick();
    expect(DaemonJob::query()->where('kind', 'package_install')->count())->toBe(2);

    pkgFinish($machines[1], pkgJob($machines[1], 'package_install')->id, result: ['manager' => 'apt', 'applied' => true, 'reboot_required' => true]);
    pkgTick();
    expect(DaemonJob::query()->where('kind', 'package_install')->pluck('machine_id')->all())->toContain($machines[2]->id);

    pkgFinish($machines[2], pkgJob($machines[2], 'package_install')->id, result: ['manager' => 'apt', 'applied' => true, 'reboot_required' => false]);
    pkgTick();

    $detail = PackageRollouts::detail($rollout->fresh());
    expect($detail['state'])->toBe('done')
        ->and($detail['finished'])->toBe(3)
        ->and(collect($detail['machines'])->pluck('outcome')->all())->toBe(['Installed.', 'Installed. A restart is needed to finish.', 'Installed.'])
        ->and(DaemonJob::query()->where('kind', 'reboot')->exists())->toBeFalse()
        ->and(Activity::query()->where('action', 'updates.rollout_done')->value('meta'))->toMatchArray(['state' => 'done', 'done' => 3]);
});

test('a rollout stops at a failure, and skips machines that are offline or too old', function () {
    $workspace = pkgWorkspace();
    $offline = pkgMachine($workspace, 'a-offline', ['last_seen_at' => now()->subMinutes(10)]);
    $old = pkgMachine($workspace, 'b-old', ['daemon_version' => 'v0.1.1-pre.25']);
    $first = pkgMachine($workspace, 'c-first');
    $last = pkgMachine($workspace, 'd-last');
    collect([$offline, $old, $first, $last])->each(fn (Machine $m) => pkgSnapshot($m, ['openssl' => ['3.0.13-0ubuntu3.4', '3.0.13-0ubuntu3.5', true]]));

    $this->actingAs($workspace->owner)->postJson(pkgUrl($workspace, '/updates/rollouts'), [
        'packages' => [['name' => 'openssl', 'version' => '3.0.13-0ubuntu3.5']], 'mode' => 'exact', 'batch_size' => 1,
    ])->assertCreated();

    // Offline and too old are passed over at once; the first that can goes.
    $rollout = PackageRollout::query()->sole();
    expect(pkgSent())->toBe([['type' => 'package_install_versions', 'packages' => [['name' => 'openssl', 'version' => '3.0.13-0ubuntu3.5']], 'allow_downgrade' => false]])
        ->and($rollout->targets()->pluck('state', 'machine_id')->all())->toBe([$offline->id => 'skipped', $old->id => 'skipped', $first->id => 'running', $last->id => 'pending'])
        ->and($rollout->targets()->where('machine_id', $old->id)->value('outcome'))->toContain('Daemon too old for exact versions')
        ->and($rollout->targets()->where('machine_id', $offline->id)->value('outcome'))->toContain('offline');

    pkgFinish($first, pkgJob($first, 'package_install_versions')->id, 'failed', error: ['message' => 'E: Version 3.0.13-0ubuntu3.5 for openssl was not found']);
    pkgTick();

    $rollout->refresh();
    expect($rollout->state)->toBe('failed')
        ->and($rollout->targets()->where('machine_id', $first->id)->value('outcome'))->toBe('The install didn’t finish: E: Version 3.0.13-0ubuntu3.5 for openssl was not found.')
        ->and($rollout->targets()->where('machine_id', $last->id)->first()->only(['state', 'outcome']))->toBe(['state' => 'skipped', 'outcome' => 'Not started: the rollout stopped after c-first failed.'])
        ->and(DaemonJob::query()->where('machine_id', $last->id)->where('kind', 'package_install_versions')->exists())->toBeFalse();
});

test('a rollout restarts machines that need it, holding their alerts', function () {
    $workspace = pkgWorkspace();
    $machine = pkgMachine($workspace, 'web-1');
    pkgSnapshot($machine, ['linux-image-generic' => ['6.8.0-45.45', '6.8.0-47.47', true]]);

    $this->actingAs($workspace->owner)->postJson(pkgUrl($workspace, '/updates/rollouts'), ['all' => true, 'mode' => 'latest', 'reboot' => 'if_required'])->assertCreated();
    expect(pkgSent())->toBe([['type' => 'package_updates', 'apply' => true, 'security_only' => false]]);

    pkgFinish($machine, pkgJob($machine, 'package_updates')->id, result: ['manager' => 'apt', 'applied' => true, 'reboot_required' => true]);
    pkgTick();
    $reboot = pkgJob($machine, 'reboot');
    expect($machine->fresh()->inMaintenance())->toBeTrue()
        ->and(PackageRollout::query()->sole()->state)->toBe('running')
        ->and(Activity::query()->where('action', 'machine.reboot')->exists())->toBeTrue();

    // Back: the daemon connected again after the restart.
    $this->travel(2)->minutes();
    $machine->forceFill(['connected_at' => now(), 'last_seen_at' => now()])->save();
    pkgTick();

    $rollout = PackageRollout::query()->sole();
    expect($rollout->state)->toBe('done')
        ->and($rollout->targets()->value('outcome'))->toBe('Installed, and back after a restart.')
        ->and($rollout->targets()->value('reboot_job_id'))->toBe($reboot->id);
});

test('cancelling stops further batches; what’s running finishes without a restart', function () {
    $workspace = pkgWorkspace();
    $one = pkgMachine($workspace, 'web-1');
    $two = pkgMachine($workspace, 'web-2');
    collect([$one, $two])->each(fn (Machine $m) => pkgSnapshot($m, ['curl' => ['8.5.0-2ubuntu10.5', '8.5.0-2ubuntu10.6']]));

    $this->actingAs($workspace->owner)->postJson(pkgUrl($workspace, '/updates/rollouts'), ['packages' => [['name' => 'curl']], 'mode' => 'latest', 'batch_size' => 1, 'reboot' => 'if_required'])->assertCreated();
    $rollout = PackageRollout::query()->sole();

    $this->deleteJson(pkgUrl($workspace, "/updates/rollouts/{$rollout->id}"))->assertOk()->assertJsonPath('rollout.state', 'cancelled');
    expect($rollout->targets()->where('machine_id', $two->id)->value('state'))->toBe('skipped')
        ->and(Activity::query()->where('action', 'updates.rollout_cancel')->exists())->toBeTrue();

    pkgFinish($one, pkgJob($one, 'package_install')->id, result: ['manager' => 'apt', 'applied' => true, 'reboot_required' => true]);
    pkgTick();
    expect($rollout->targets()->where('machine_id', $one->id)->first()->only(['state', 'outcome']))->toBe(['state' => 'done', 'outcome' => 'Installed. Not restarted, as the rollout was cancelled.'])
        ->and(DaemonJob::query()->where('kind', 'reboot')->exists())->toBeFalse()
        ->and(DaemonJob::query()->where('machine_id', $two->id)->where('kind', 'package_install')->exists())->toBeFalse()
        ->and($rollout->fresh()->state)->toBe('cancelled');

    $this->deleteJson(pkgUrl($workspace, "/updates/rollouts/{$rollout->id}"))->assertStatus(409);
});

test('a rollout planned for later waits for its time', function () {
    $workspace = pkgWorkspace();
    $machine = pkgMachine($workspace, 'web-1');
    pkgSnapshot($machine, ['curl' => ['8.5.0-2ubuntu10.5', '8.5.0-2ubuntu10.6']]);

    $this->actingAs($workspace->owner)->postJson(pkgUrl($workspace, '/updates/rollouts'), ['packages' => [['name' => 'curl']], 'mode' => 'latest', 'scheduled_for' => now()->addHours(3)->toIso8601String()])
        ->assertCreated()->assertJsonPath('rollout.state', 'pending');
    pkgTick();
    expect(pkgSent())->toBe([]);

    $this->travel(3)->hours();
    $machine->forceFill(['last_seen_at' => now()])->save();
    pkgTick();
    expect(pkgSent())->toBe([['type' => 'package_install', 'packages' => ['curl']]]);
});

test('exact versions must be offered on every machine chosen', function () {
    $workspace = pkgWorkspace();
    $noble = pkgMachine($workspace, 'noble-1');
    $jammy = pkgMachine($workspace, 'jammy-1', ['os_release' => ['id' => 'ubuntu', 'version_id' => '22.04', 'pretty_name' => 'Ubuntu 22.04.5 LTS']]);
    $current = pkgMachine($workspace, 'noble-2');
    pkgSnapshot($noble, ['openssl' => ['3.0.13-0ubuntu3.4', '3.0.13-0ubuntu3.5', true]]);
    pkgSnapshot($jammy, ['openssl' => ['3.0.2-0ubuntu1.17', '3.0.2-0ubuntu1.18', true]]);
    // Already on it: a row whose installed version matches.
    pkgSnapshot($current, ['openssl' => ['3.0.13-0ubuntu3.5', '3.0.13-0ubuntu3.6', true]]);
    $url = pkgUrl($workspace, '/updates/rollouts');

    $this->actingAs($workspace->owner)->postJson($url, ['packages' => [['name' => 'openssl', 'version' => '3.0.13-0ubuntu3.5']], 'mode' => 'exact', 'machines' => [$noble->id, $jammy->id, $current->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['packages' => 'openssl 3.0.13-0ubuntu3.5 isn’t offered on jammy-1.']);

    $this->postJson($url, ['packages' => [['name' => 'openssl']], 'mode' => 'exact', 'machines' => [$noble->id]])->assertJsonValidationErrors(['packages' => 'Pick a version for openssl.']);
    $this->postJson($url, ['packages' => [['name' => 'openssl', 'version' => '--force']], 'mode' => 'exact'])->assertJsonValidationErrors('packages.0.version');
    $this->postJson($url, ['packages' => [['name' => 'openssl']], 'mode' => 'latest', 'machines' => [Machine::factory()->create()->id]])->assertJsonValidationErrors('machines');
    expect(PackageRollout::query()->count())->toBe(0);

    // The one already on it is done straight away; the other installs.
    $this->postJson($url, ['packages' => [['name' => 'openssl', 'version' => '3.0.13-0ubuntu3.5']], 'mode' => 'exact', 'machines' => [$noble->id, $current->id]])->assertCreated();
    expect(PackageRollout::query()->sole()->targets()->pluck('outcome', 'machine_id')->all())->toBe([$noble->id => null, $current->id => 'Already on these versions.']);
});

test('holding packages needs a manager and a daemon that can, and keeps what the daemon reports', function () {
    $workspace = pkgWorkspace();
    $new = pkgMachine($workspace, 'web-1');
    $old = pkgMachine($workspace, 'web-2', ['daemon_version' => 'v0.1.1-pre.25']);
    $url = pkgUrl($workspace, '/updates/holds');
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    $this->actingAs($viewer)->postJson($url, ['packages' => ['nginx'], 'machines' => [$new->id], 'hold' => true])->assertForbidden();
    $this->actingAs($workspace->owner)->postJson($url, ['packages' => ['nginx'], 'machines' => [$old->id], 'hold' => true])
        ->assertStatus(409)->assertJsonPath('skipped.0.reason', 'Its daemon is too old to hold packages. Update it first with “sudo serverosd update”.');
    $this->postJson($url, ['packages' => ['-y'], 'machines' => [$new->id], 'hold' => true])->assertJsonValidationErrors('packages.0');

    $this->postJson($url, ['packages' => ['nginx', 'docker-ce'], 'machines' => [$new->id, $old->id], 'hold' => true])
        ->assertAccepted()->assertJsonCount(1, 'queued')->assertJsonPath('skipped.0.machine', 'web-2');
    expect(pkgSent())->toBe([['type' => 'package_hold', 'packages' => ['nginx', 'docker-ce'], 'hold' => true]])
        ->and(Activity::query()->where('action', 'updates.hold')->value('meta'))->toMatchArray(['packages' => 2, 'machines' => 1]);

    pkgFinish($new, pkgJob($new, 'package_hold')->id, result: ['manager' => 'apt', 'held' => ['nginx', 'docker-ce']]);
    expect($new->fresh()->held_packages)->toBe(['docker-ce', 'nginx']);

    // dnf without its versionlock plugin: the daemon's words come back with the job.
    $this->postJson($url, ['packages' => ['nginx'], 'machines' => [$new->id], 'hold' => false])->assertAccepted();
    $job = pkgJob($new, 'package_hold');
    pkgFinish($new, $job->id, 'failed', error: ['message' => 'dnf versionlock plugin is not installed']);
    $this->getJson(pkgUrl($workspace, "/machines/{$new->id}/updates/{$job->id}"))->assertOk()->assertJsonPath('error.message', 'dnf versionlock plugin is not installed');
    expect($new->fresh()->held_packages)->toBe(['docker-ce', 'nginx']);
});

test('the Updates page shows every machine’s updates, and viewers can only look', function () {
    $workspace = pkgWorkspace();
    $one = pkgMachine($workspace, 'web-1', ['updates_checked_at' => now()->subHours(2)]);
    $two = pkgMachine($workspace, 'web-2', ['os_release' => ['id' => 'ubuntu', 'version_id' => '22.04', 'pretty_name' => 'Ubuntu 22.04.5 LTS']]);
    pkgSnapshot($one, ['openssl' => ['3.0.13-0ubuntu3.4', '3.0.13-0ubuntu3.5', true], 'curl' => ['8.5.0-2ubuntu10.5', '8.5.0-2ubuntu10.6']], ['held' => ['nginx']]);
    pkgSnapshot($two, ['openssl' => ['3.0.2-0ubuntu1.17', '3.0.2-0ubuntu1.18', true]]);
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    $this->actingAs($viewer)->get(pkgUrl($workspace, '/updates'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('workspace/Updates')
        ->where('summary.updates', 3)
        ->where('summary.security', 2)
        ->where('summary.machines', 2)
        ->where('packages.0.name', 'openssl')
        ->where('packages.0.security', true)
        ->has('packages.0.versions', 2)
        ->where('packages.0.versions.0.machineIds', [$one->id])
        ->where('packages.1.name', 'curl')
        ->where('packages.2.name', 'nginx')
        ->where('packages.2.heldOn', [$one->id])
        ->where('machines.0.name', 'web-1')
        ->where('machines.0.os', 'Ubuntu 24.04.1 LTS')
        ->where('machines.0.updates', 2)
        ->where('machines.0.held', ['nginx'])
        ->where('workspaceAccess.permissions', fn ($permissions) => ! collect($permissions)->contains('manage-machines')));

    $this->postJson(pkgUrl($workspace, '/updates/check'))->assertForbidden();
    $this->postJson(pkgUrl($workspace, '/updates/rollouts'), ['packages' => [['name' => 'curl']], 'mode' => 'latest'])->assertForbidden();
    $this->getJson(pkgUrl($workspace, '/machines/'.$one->id.'/updates'))->assertOk()->assertJsonCount(2, 'packages');

    // A manager checks every machine at once.
    $this->actingAs($workspace->owner)->postJson(pkgUrl($workspace, '/updates/check'))->assertAccepted()->assertJsonPath('queued', 2);
    expect(Activity::query()->where('action', 'updates.check_all')->value('meta'))->toMatchArray(['machines' => 2]);

    // Another workspace's rollout isn't reachable.
    $other = PackageRollout::query()->create(['workspace_id' => Workspace::factory()->create()->id, 'name' => 'x', 'packages' => [], 'mode' => 'latest']);
    $this->getJson(pkgUrl($workspace, "/updates/rollouts/{$other->id}"))->assertNotFound();
});
