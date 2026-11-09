<?php

use App\Models\Backup;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Workspace;
use App\Notifications\MonitorAlert;
use App\Support\Health\JobAlerts;
use App\Support\Health\MachineWatcher;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/** @return array{0: Workspace, 1: Machine} */
function alertingMachine(): array
{
    Notification::fake();
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'environment' => 'production']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()])->save();

    return [$workspace, $machine];
}

function backupFinishing(Machine $machine, string $state, ?string $error = null): Backup
{
    $backup = $machine->backups()->create(['service' => 'docker:db', 'service_name' => 'postgres', 'trigger' => 'scheduled', 'state' => $state]);
    $backup->forceFill(['error' => $error, 'finished_at' => now()])->save();
    app(JobAlerts::class)->backupFinished($backup);

    return $backup;
}

function deployFinishing(Machine $machine, string $state, ?string $error = null): DaemonJob
{
    static $tick = 0;
    $job = DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'deploy', 'service' => 'api',
        'state' => $state, 'error' => $error ? ['message' => $error] : null, 'finished_at' => now(),
    ]);
    $job->forceFill(['created_at' => now()->addSeconds(++$tick)])->save();
    app(JobAlerts::class)->deployFinished($job);

    return $job;
}

/** @return list<string> */
function alertSubjects(Workspace $workspace): array
{
    return Notification::sent($workspace->owner, MonitorAlert::class)->map(fn (MonitorAlert $n) => $n->subject)->all();
}

test('a failing backup alerts once, not for every failure in a row, and again when it works', function () {
    [$workspace, $machine] = alertingMachine();

    backupFinishing($machine, 'succeeded');
    backupFinishing($machine, 'failed', 'The bucket said 403 Forbidden.');
    backupFinishing($machine, 'failed', 'The bucket said 403 Forbidden.');
    backupFinishing($machine, 'succeeded');
    backupFinishing($machine, 'succeeded');

    expect(alertSubjects($workspace))->toBe([
        'postgres on web-1 couldn’t be backed up',
        'postgres on web-1 is backing up again',
    ]);
    Notification::assertSentTo($workspace->owner, MonitorAlert::class, fn (MonitorAlert $n) => in_array('The bucket said 403 Forbidden.', $n->lines, true));
});

test('a failing deploy alerts once with why, and again when one works', function () {
    [$workspace, $machine] = alertingMachine();

    deployFinishing($machine, 'succeeded');
    deployFinishing($machine, 'failed', 'npm run build exited with 1');
    deployFinishing($machine, 'timed_out');
    deployFinishing($machine, 'cancelled');
    deployFinishing($machine, 'succeeded');

    expect(alertSubjects($workspace))->toBe([
        'api on web-1 failed to deploy',
        'api on web-1 deployed',
    ]);
    Notification::assertSentTo($workspace->owner, MonitorAlert::class, fn (MonitorAlert $n) => in_array('npm run build exited with 1', $n->lines, true));
});

test('the first deploy failing alerts, and a first success says nothing', function () {
    [$workspace, $machine] = alertingMachine();

    deployFinishing($machine, 'succeeded');
    expect(alertSubjects($workspace))->toBe([]);

    [$other, $fresh] = alertingMachine();
    deployFinishing($fresh, 'failed');
    expect(alertSubjects($other))->toBe(['api on web-1 failed to deploy']);
});

test('machines under planned work stay quiet', function () {
    [$workspace, $machine] = alertingMachine();
    $machine->forceFill(['maintenance_until' => now()->addHour()])->save();

    backupFinishing($machine, 'failed');
    deployFinishing($machine, 'failed');

    expect(alertSubjects($workspace))->toBe([]);
});

test('memory alerts once it stays high, not for a spike, and again when it’s back to normal', function () {
    [$workspace, $machine] = alertingMachine();
    $machine->forceFill(['facts' => ['memory_bytes' => 4000 * 1048576]])->save();
    $watch = fn () => app(MachineWatcher::class)->run();
    $memory = fn (int $percent) => $machine->forceFill(['memory_used_mb' => 40 * $percent, 'last_seen_at' => now()])->save();

    // A spike: high for a few minutes, then down.
    $memory(95);
    expect($watch())->toBe(0);
    $this->travel(5)->minutes();
    $memory(95);
    expect($watch())->toBe(0);
    $memory(70);
    expect($watch())->toBe(0);

    // Staying high.
    $memory(96);
    $watch();
    $this->travel(11)->minutes();
    $memory(96);
    expect($watch())->toBe(1)->and($watch())->toBe(0);

    // Hovering under the line doesn't clear it; well under does.
    $memory(88);
    expect($watch())->toBe(0);
    $memory(60);
    expect($watch())->toBe(1);

    expect(alertSubjects($workspace))->toBe([
        'web-1 is using 96% of its memory',
        'web-1’s memory is back to normal',
    ]);
});

test('a deploy the daemon reports as failed alerts, through the gateway', function () {
    [$workspace, $machine] = alertingMachine();
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    $job = DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'deploy', 'service' => 'api', 'state' => 'running']);

    $this->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', [
        'envelopes' => [['v' => 1, 'kind' => 'job_update', 'payload' => ['job_id' => $job->id, 'state' => 'failed', 'error' => ['message' => 'Health check never passed']]]],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();

    expect(alertSubjects($workspace))->toBe(['api on web-1 failed to deploy']);
});
