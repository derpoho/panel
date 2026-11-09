<?php

use App\Enums\Plan;
use App\Models\LogEntry;
use App\Models\Machine;
use App\Models\Workspace;
use App\Models\WorkspaceLogStore;
use App\Support\Logs\LogBuffer;
use App\Support\Logs\LogQuota;
use App\Support\Logs\LogStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
});

/**
 * @return array{0: Workspace, 1: Machine, 2: WorkspaceLogStore}
 */
function lsiMachine(): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();
    $machine = Machine::factory()->for($workspace)->create();
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()])->save();

    return [$workspace, $machine, ownLogStore($workspace)];
}

/** @param  list<string>  $messages */
function lsiShip(Machine $machine, array $messages): void
{
    $lines = array_map(fn (string $message) => ['service' => 'docker:aaa', 'name' => 'web', 'ts' => now()->getTimestampMs(), 'stream' => 'stdout', 'line' => $message], $messages);

    test()->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', [
        'envelopes' => [['v' => 1, 'kind' => 'logs', 'payload' => ['lines' => $lines, 'dropped' => 0]]],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();
}

/** Point the store at a file that can't exist, as if their database were down. */
function lsiBreak(WorkspaceLogStore $store): string
{
    $path = $store->database;
    lsiMove($store, sys_get_temp_dir().'/serveros-missing-'.Str::random(8).'/logs.sqlite');

    return $path;
}

function lsiMove(WorkspaceLogStore $store, string $path): void
{
    $store->forceFill(['database' => $path])->save();
    // install() left a connection open to the old file; flush() only
    // purges connections it resolved itself.
    DB::purge('logs_ws_'.$store->workspace_id);
    LogStore::flush();
}

/** @return array{machine_id: int, service_key: string, service: string, logged_at: int, level: string, stream: string, message: string} */
function lsiRow(int $machineId, string $message): array
{
    return ['machine_id' => $machineId, 'service_key' => 'docker:aaa', 'service' => 'web', 'logged_at' => 1, 'level' => 'info', 'stream' => 'stdout', 'message' => $message];
}

test('lines and their counts land in the workspace\'s own store, not ours', function () {
    [$workspace, $machine, $store] = lsiMachine();

    lsiShip($machine, ['one', 'two']);

    expect(LogStore::entries($workspace)->pluck('message')->all())->toBe(['one', 'two'])
        ->and((int) LogStore::counts($workspace)->sum('n'))->toBe(2)
        ->and(LogEntry::query()->count())->toBe(0)
        ->and(DB::table('log_counts')->count())->toBe(0)
        ->and($store->fresh()->status)->toBe('ok')
        ->and($store->fresh()->last_ok_at)->not->toBeNull();
});

test('the daily allowance doesn\'t apply to a workspace with its own store', function () {
    [$workspace, $machine] = lsiMachine();
    Cache::put('log-quota:'.$workspace->id.':'.now('UTC')->format('Ymd').':kept', $workspace->logLinesPerDay(), now()->addDay());

    lsiShip($machine, ['one', 'two', 'three']);

    expect(LogStore::entries($workspace)->count())->toBe(3)
        ->and(LogQuota::today($workspace))->toMatchArray(['exempt' => true, 'dropped' => 0, 'kept' => $workspace->logLinesPerDay()]);
});

test('an unreachable store gets its lines buffered, never written to ours', function () {
    [$workspace, $machine, $store] = lsiMachine();
    lsiBreak($store);

    lsiShip($machine, ['one', 'two']);
    lsiShip($machine, ['three']);

    expect(LogBuffer::size($workspace->id))->toBe(3)
        ->and(LogEntry::query()->count())->toBe(0)
        ->and(DB::table('log_counts')->count())->toBe(0)
        ->and($store->fresh()->status)->toBe('unreachable')
        ->and($store->fresh()->last_error)->not->toBeNull();
});

test('buffered lines are delivered once the store works again', function () {
    [$workspace, $machine, $store] = lsiMachine();
    $path = lsiBreak($store);
    lsiShip($machine, ['one', 'two']);

    // Still down: the command leaves them buffered.
    $this->artisan('logs:flush-buffers')->assertSuccessful();
    expect(LogBuffer::size($workspace->id))->toBe(2);

    lsiMove($store, $path);
    $this->artisan('logs:flush-buffers')->assertSuccessful();

    expect(LogBuffer::size($workspace->id))->toBe(0)
        ->and(LogStore::entries($workspace)->pluck('message')->all())->toBe(['one', 'two'])
        ->and((int) LogStore::counts($workspace)->sum('n'))->toBe(2)
        ->and($store->fresh()->status)->toBe('ok')
        ->and($store->fresh()->last_error)->toBeNull();

    // And new lines go straight in again.
    lsiShip($machine, ['three']);
    expect(LogStore::entries($workspace)->count())->toBe(3);
});

test('the buffer drops its oldest lines past the cap, and counts them', function () {
    $rows = array_map(fn (int $i) => lsiRow(1, "line {$i}"), range(1, LogBuffer::CAP));
    foreach (array_chunk($rows, 700) as $chunk) {
        LogBuffer::push(99, $chunk);
    }
    LogBuffer::push(99, [lsiRow(1, 'newest one'), lsiRow(1, 'newest two')]);

    expect(LogBuffer::size(99))->toBe(LogBuffer::CAP)
        ->and(LogBuffer::dropped(99))->toBe(2)
        ->and(array_column(LogBuffer::take(99, 2), 'message'))->toBe(['line 3', 'line 4']);

    LogBuffer::restore(99, [lsiRow(1, 'back')]);
    expect(LogBuffer::take(99, 1)[0]['message'])->toBe('back');

    $rest = LogBuffer::take(99, LogBuffer::CAP);
    expect($rest)->toHaveCount(LogBuffer::CAP - 2)
        ->and(end($rest)['message'])->toBe('newest two')
        ->and(LogBuffer::size(99))->toBe(0);
});
