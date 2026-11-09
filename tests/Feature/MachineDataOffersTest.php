<?php

use App\Enums\Plan;
use App\Jobs\ClearMachineData;
use App\Models\Activity;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use App\Support\Logs\LogBuffer;
use App\Support\Logs\LogSkipRules;
use App\Support\Logs\LogStore;
use App\Support\Machines\MachineDataEraser;
use App\Support\Machines\MachinePolicies;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * Narrowing what a machine sends (skip rules, logs off in its policy)
 * offers to delete what it already sent.
 */

beforeEach(function () {
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['ok' => true])]);
});

function offerMachine(Workspace $workspace, string $name = 'web-1'): Machine
{
    $machine = Machine::factory()->for($workspace)->create(['name' => $name]);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'certificate_serial' => 'serial-'.$name, 'daemon_version' => 'v0.1.1-pre.22', 'connected_at' => now()->subDay(), 'last_seen_at' => now()])->save();

    return $machine->fresh();
}

/** Lines from one service, with the chart's counts of them across two days. */
function offerLogs(Machine $machine, string $service, int $lines): void
{
    $now = now()->getTimestampMs();
    foreach (array_chunk(range(1, $lines), 500) as $chunk) {
        LogStore::entries($machine->workspace_id)->insert(array_map(fn (int $i) => [
            'machine_id' => $machine->id, 'service_key' => 'docker:'.$service, 'service' => $service,
            'logged_at' => $now - $i * 1000, 'level' => 'info', 'stream' => 'stdout', 'message' => "{$service} {$i}",
        ], $chunk));
    }
    $minute = $now - $now % 60_000;
    LogStore::counts($machine->workspace_id)->insert([
        ['machine_id' => $machine->id, 'minute' => $minute, 'service' => $service, 'level' => 'info', 'n' => $lines - 1],
        ['machine_id' => $machine->id, 'minute' => $minute - 2 * 86_400_000, 'service' => $service, 'level' => 'info', 'n' => 1],
    ]);
}

function offerUrl(Workspace $workspace, Machine $machine, string $path): string
{
    return 'https://'.$workspace->host().'/machines/'.$machine->id.'/'.$path;
}

function offerLines(Machine $machine, ?string $service = null): int
{
    return LogStore::entries($machine->workspace_id)->where('machine_id', $machine->id)
        ->when($service !== null, fn ($q) => $q->where('service', $service))->count();
}

test('skip globs match service names the way the daemon does', function () {
    expect(LogSkipRules::matchesService('ptero-*', 'ptero-abc'))->toBeTrue()
        ->and(LogSkipRules::matchesService('Wings', '/wings'))->toBeTrue()
        ->and(LogSkipRules::matchesService('nginx', 'nginx.service'))->toBeTrue()
        ->and(LogSkipRules::matchesService('*b*', 'abc'))->toBeTrue()
        ->and(LogSkipRules::matchesService('a*c', 'ab'))->toBeFalse()
        ->and(LogSkipRules::matchesService('abc', 'abcd'))->toBeFalse()
        ->and(LogSkipRules::matchesService('*', ''))->toBeTrue();
});

test('adding service skip rules offers the matching services and their line counts', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = offerMachine($workspace);
    offerLogs($machine, 'ptero-1', 30);
    offerLogs($machine, 'ptero-2', 10);
    offerLogs($machine, 'wings', 8);
    offerLogs($machine, 'nginx', 5);

    $this->actingAs($workspace->owner)
        ->put(offerUrl($workspace, $machine, 'log-skip'), ['services' => ['ptero-*', 'wings', 'redis'], 'patterns' => []])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('logSkipOffer', [
            'services' => [
                ['service' => 'ptero-1', 'lines' => 30],
                ['service' => 'ptero-2', 'lines' => 10],
                ['service' => 'wings', 'lines' => 8],
            ],
            'lines' => 48,
            'patterns' => false,
        ]);
});

test('only newly added globs count, and unchanged or removed rules offer nothing', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = offerMachine($workspace);
    $machine->forceFill(['log_skip' => ['services' => ['wings'], 'patterns' => []]])->save();
    offerLogs($machine, 'wings', 8);
    offerLogs($machine, 'ptero-1', 4);
    $url = offerUrl($workspace, $machine, 'log-skip');

    $this->flushSession()->actingAs($workspace->owner)->put($url, ['services' => ['wings', 'ptero-*'], 'patterns' => []])
        ->assertInertiaFlash('logSkipOffer.services', [['service' => 'ptero-1', 'lines' => 4]]);

    $this->flushSession()->actingAs($workspace->owner)->put($url, ['services' => ['wings', 'ptero-*'], 'patterns' => []])
        ->assertInertiaFlashMissing('logSkipOffer');
    $this->flushSession()->actingAs($workspace->owner)->put($url, ['services' => ['wings'], 'patterns' => []])
        ->assertInertiaFlashMissing('logSkipOffer');
    // Same rule, different case: not new to the daemon.
    $this->flushSession()->actingAs($workspace->owner)->put($url, ['services' => ['WINGS'], 'patterns' => []])
        ->assertInertiaFlashMissing('logSkipOffer');
});

test('a new line pattern only says past matches stay', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = offerMachine($workspace);
    offerLogs($machine, 'shop', 5);

    $this->actingAs($workspace->owner)
        ->put(offerUrl($workspace, $machine, 'log-skip'), ['services' => [], 'patterns' => [['service' => null, 'pattern' => 'password=\S+']]])
        ->assertInertiaFlash('logSkipOffer', ['services' => [], 'lines' => 0, 'patterns' => true]);

    expect(offerLines($machine))->toBe(5);
});

test('deleting skipped services removes only their lines and counts on that machine', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $web = offerMachine($workspace, 'web-1');
    $db = offerMachine($workspace, 'db-1');
    $web->forceFill(['log_skip' => ['services' => ['ptero-*', 'wings'], 'patterns' => []]])->save();
    foreach ([$web, $db] as $machine) {
        offerLogs($machine, 'ptero-1', 30);
        offerLogs($machine, 'wings', 8);
        offerLogs($machine, 'nginx', 5);
    }
    $connection = $web->fresh()->only(['uid', 'certificate_serial', 'connected_at', 'last_seen_at']);
    Http::fake(['*' => Http::response(['ok' => true])]);

    $this->actingAs($workspace->owner)
        ->post(offerUrl($workspace, $web, 'collected-data/skipped-services'), ['services' => ['ptero-1', 'wings', 'nginx']])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // nginx isn't skipped, so it wasn't taken.
    expect(offerLines($web, 'ptero-1'))->toBe(0)
        ->and(offerLines($web, 'wings'))->toBe(0)
        ->and(offerLines($web, 'nginx'))->toBe(5)
        ->and(LogStore::counts($workspace)->where('machine_id', $web->id)->pluck('service')->unique()->values()->all())->toBe(['nginx'])
        ->and(offerLines($db))->toBe(43)
        ->and(LogStore::counts($workspace)->where('machine_id', $db->id)->count())->toBe(6)
        // Still connected, and nothing was sent to it.
        ->and($web->fresh()->only(['uid', 'certificate_serial', 'connected_at', 'last_seen_at']))->toEqual($connection);
    Http::assertNothingSent();

    $activity = Activity::query()->where('action', 'machine.data_clear')->sole();
    expect($activity->machine_id)->toBe($web->id)
        ->and($activity->meta)->toBe(['logs' => 38, 'skipped_services' => 2]);
    $line = ActivityLine::describe($activity, false);
    expect($line['verb'])->toBe('deleted logs from 2 skipped services on')
        ->and($line['subject'])->toBe('web-1')
        ->and($line['after'])->toBe('38 log lines');
    expect(MachineDataEraser::status($web))->toMatchArray(['state' => 'done', 'kinds' => ['logs'], 'counts' => ['logs' => 38], 'services' => ['ptero-1', 'wings']]);
});

test('services the machine doesn’t skip can’t be deleted this way', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = offerMachine($workspace);
    offerLogs($machine, 'nginx', 5);

    $this->actingAs($workspace->owner)
        ->post(offerUrl($workspace, $machine, 'collected-data/skipped-services'), ['services' => ['nginx']])
        ->assertSessionHasErrors('services');

    expect(offerLines($machine))->toBe(5)
        ->and(Activity::query()->where('action', 'machine.data_clear')->exists())->toBeFalse();
});

test('a workspace with its own log store has skipped services deleted there, buffered lines too', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();
    ownLogStore($workspace);
    $machine = offerMachine($workspace);
    $other = offerMachine($workspace, 'db-1');
    $machine->forceFill(['log_skip' => ['services' => ['wings'], 'patterns' => []]])->save();
    offerLogs($machine, 'wings', 6);
    offerLogs($machine, 'shop', 3);
    offerLogs($other, 'wings', 2);
    $row = fn (Machine $m, string $service) => ['machine_id' => $m->id, 'service_key' => 'docker:'.$service, 'service' => $service, 'logged_at' => 1, 'level' => 'info', 'stream' => 'stdout', 'message' => 'waiting'];
    LogBuffer::push($workspace->id, [$row($machine, 'wings'), $row($machine, 'shop'), $row($other, 'wings')]);

    expect(LogStore::isExternal($workspace))->toBeTrue();

    $this->actingAs($workspace->owner)
        ->post(offerUrl($workspace, $machine, 'collected-data/skipped-services'), ['services' => ['wings']])
        ->assertSessionHasNoErrors();

    expect(offerLines($machine, 'wings'))->toBe(0)
        ->and(offerLines($machine, 'shop'))->toBe(3)
        ->and(offerLines($other, 'wings'))->toBe(2)
        ->and(LogStore::counts($workspace)->where('machine_id', $machine->id)->where('service', 'wings')->count())->toBe(0)
        ->and(LogBuffer::size($workspace->id))->toBe(2)
        // 6 stored and 1 waiting.
        ->and(Activity::query()->where('action', 'machine.data_clear')->sole()->meta)->toBe(['logs' => 7, 'skipped_services' => 1]);
});

test('deleting skipped services past the limit runs in the background', function () {
    Queue::fake();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = offerMachine($workspace);
    $machine->forceFill(['log_skip' => ['services' => ['wings'], 'patterns' => []]])->save();
    offerLogs($machine, 'wings', 10);
    LogStore::counts($workspace)->where('machine_id', $machine->id)->where('service', 'wings')->where('n', 9)->update(['n' => MachineDataEraser::SYNC_LIMIT]);

    $this->actingAs($workspace->owner)
        ->post(offerUrl($workspace, $machine, 'collected-data/skipped-services'), ['services' => ['wings']])
        ->assertSessionHasNoErrors();

    Queue::assertPushed(ClearMachineData::class, fn (ClearMachineData $job) => $job->machineId === $machine->id && $job->services === ['wings'] && $job->kinds === ['logs'] && ! $job->rescan);
    expect(offerLines($machine))->toBe(10)
        ->and(MachineDataEraser::status($machine)['state'])->toBe('queued')
        ->and(Activity::query()->where('action', 'machine.data_clear')->sole()->meta)->toBe(['logs' => MachineDataEraser::SYNC_LIMIT + 1, 'queued' => true, 'skipped_services' => 1]);

    $job = Queue::pushed(ClearMachineData::class)->first();
    $job->handle(app(MachineDataEraser::class));
    expect(offerLines($machine))->toBe(0)
        ->and(MachineDataEraser::status($machine))->toMatchArray(['state' => 'done', 'counts' => ['logs' => 10], 'services' => ['wings']]);
});

test('turning logs off in a policy can delete what its machines already sent, when asked', function () {
    Queue::fake();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = offerMachine($workspace);
    offerLogs($machine, 'shop', 12);
    $policy = MachinePolicies::defaultFor($workspace);
    $url = 'https://'.$workspace->host().'/settings/machine-policies/'.$policy->id;
    $settings = ['name' => 'Default', ...$policy->settings()];

    // Off without asking: the lines stay.
    $this->actingAs($workspace->owner)->put($url, [...$settings, 'logs' => false])->assertSessionHasNoErrors();
    Queue::assertNotPushed(ClearMachineData::class);

    $this->actingAs($workspace->owner)->put($url, [...$settings, 'logs' => true]);
    $this->actingAs($workspace->owner)->put($url, [...$settings, 'logs' => false, 'clear_logs' => true])->assertSessionHasNoErrors();
    Queue::assertPushed(ClearMachineData::class, fn (ClearMachineData $job) => $job->machineId === $machine->id && $job->kinds === ['logs'] && ! $job->rescan);

    Queue::pushed(ClearMachineData::class)->first()->handle(app(MachineDataEraser::class));
    expect(offerLines($machine))->toBe(0);

    // Masking turned on can clear what went out with full addresses; only
    // changing how it masks can't.
    $this->actingAs($workspace->owner)->put($url, [...$settings, 'logs' => true, 'log_ip_masking' => 'partial', 'clear_logs' => true]);
    expect(Queue::pushed(ClearMachineData::class))->toHaveCount(2);
    $this->actingAs($workspace->owner)->put($url, [...$settings, 'logs' => true, 'log_ip_masking' => 'hash', 'clear_logs' => true]);
    expect(Queue::pushed(ClearMachineData::class))->toHaveCount(2);
});

test('only people who manage machines can delete skipped services’ logs', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = offerMachine($workspace);
    $machine->forceFill(['log_skip' => ['services' => ['wings'], 'patterns' => []]])->save();
    offerLogs($machine, 'wings', 4);
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $theirs = offerMachine(Workspace::factory()->onPlan(Plan::Pro)->create(), 'theirs');
    $theirs->forceFill(['log_skip' => ['services' => ['wings'], 'patterns' => []]])->save();
    offerLogs($theirs, 'wings', 4);

    $this->actingAs($viewer)->post(offerUrl($workspace, $machine, 'collected-data/skipped-services'), ['services' => ['wings']])->assertForbidden();
    $this->actingAs($viewer)->put(offerUrl($workspace, $machine, 'log-skip'), ['services' => ['wings', 'x'], 'patterns' => []])->assertForbidden();
    $this->actingAs($workspace->owner)->post(offerUrl($workspace, $theirs, 'collected-data/skipped-services'), ['services' => ['wings']])->assertNotFound();

    expect(offerLines($machine))->toBe(4)
        ->and(offerLines($theirs))->toBe(4);
});

test('forFeature knows what the log switches offer', function () {
    expect(MachineDataEraser::forFeature('log_skip'))->toBe(['logs'])
        ->and(MachineDataEraser::forFeature('ip_masking'))->toBe(['logs']);
});
