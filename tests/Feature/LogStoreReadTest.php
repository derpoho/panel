<?php

use App\Enums\Plan;
use App\Jobs\DeleteLogLines;
use App\Models\LogEntry;
use App\Models\Machine;
use App\Models\Workspace;
use App\Models\WorkspaceLogStore;
use App\Support\Logs\LogCounts;
use App\Support\Logs\LogStore;
use App\Support\Logs\LogVolume;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

// Every test's workspace is #1, so its store's connection has the same
// name each time: drop one an earlier test left open, pointing elsewhere.
beforeEach(function () {
    foreach (array_keys(DB::getConnections()) as $name) {
        if (str_starts_with($name, 'logs_ws_')) {
            DB::purge($name);
        }
    }
});

/**
 * A Business workspace with one machine and its own log store.
 *
 * @return array{0: Workspace, 1: Machine, 2: WorkspaceLogStore}
 */
function lsrSetup(array $store = []): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'Web One']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()])->save();

    return [$workspace, $machine, ownLogStore($workspace, $store)];
}

/** Store a line, and its chart count, on a connection, as ingest would. */
function lsrLine(string $connection, Machine $machine, string $message, array $overrides = []): void
{
    $row = ['machine_id' => $machine->id, 'service_key' => 'docker:aaa', 'service' => 'umami-umami-1', 'logged_at' => now()->getTimestampMs(), 'level' => 'info', 'stream' => 'stdout', 'message' => $message, ...$overrides];
    DB::connection($connection)->table('log_entries')->insert($row);
    LogCounts::add([['machine_id' => $row['machine_id'], 'service' => $row['service'], 'logged_at' => $row['logged_at'], 'level' => $row['level']]], $connection);
}

function lsrUrl(Workspace $workspace, string $path = '/logs'): string
{
    return 'https://'.$workspace->host().$path;
}

/** Point the store at a file in a folder that isn't there, so it can't connect. */
function lsrBreak(WorkspaceLogStore $store): void
{
    $store->forceFill(['database' => sys_get_temp_dir().'/serveros-missing-'.Str::random(8).'/logs.sqlite'])->save();
    DB::purge('logs_ws_'.$store->workspace_id);
    LogStore::flush();
}

test('the Logs page reads lines, services and the chart from the workspace\'s own store', function () {
    [$workspace, $machine] = lsrSetup();
    $theirs = LogStore::connection($workspace);
    lsrLine($theirs, $machine, 'from their database');
    lsrLine($theirs, $machine, 'their nginx failed', ['service' => 'nginx.service', 'service_key' => 'systemd:nginx.service', 'level' => 'error']);
    // Left in ours from before the move: never shown.
    lsrLine(LogStore::ours(), $machine, 'left in ours', ['service' => 'old-service']);

    $this->actingAs($workspace->owner)->get(lsrUrl($workspace))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('workspace/Logs')
            ->where('logStore', ['external' => true, 'status' => 'ok', 'error' => null])
            ->where('shipping', true)
            ->has('lines', 2)
            ->where('lines.0.message', 'from their database')
            ->where('lines.1.message', 'their nginx failed')
            ->where('services', fn ($services) => collect($services)->pluck('name')->sort()->values()->all() === ['nginx.service', 'umami-umami-1'])
            ->where('histogram.buckets.59.lines', 2)
            ->where('histogram.buckets.59.error', 1));

    $this->actingAs($workspace->owner)->get(lsrUrl($workspace).'?q=nginx')
        ->assertInertia(fn (Assert $page) => $page->has('lines', 1)->where('lines.0.message', 'their nginx failed'));
});

test('a workspace on ours says so, and its page reads ours', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = Machine::factory()->for($workspace)->create();
    lsrLine(LogStore::ours(), $machine, 'ours');

    $this->actingAs($workspace->owner)->get(lsrUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page
            ->where('logStore', ['external' => false, 'status' => 'ok', 'error' => null])
            ->has('lines', 1));
});

test('following, scrolling back and context read the own store', function () {
    [$workspace, $machine] = lsrSetup();
    $theirs = LogStore::connection($workspace);
    lsrLine($theirs, $machine, 'first');
    lsrLine($theirs, $machine, 'second');
    lsrLine(LogStore::ours(), $machine, 'ours');
    $first = (int) LogStore::entries($workspace)->where('message', 'first')->value('id');

    $this->actingAs($workspace->owner)->getJson(lsrUrl($workspace, '/logs/feed?after='.$first))
        ->assertOk()->assertJsonPath('lines.0.message', 'second')->assertJsonCount(1, 'lines');
    $this->actingAs($workspace->owner)->getJson(lsrUrl($workspace, '/logs/feed?after=0'))
        ->assertJsonCount(2, 'lines');
    $this->actingAs($workspace->owner)->getJson(lsrUrl($workspace, '/logs/feed?around='.$first))
        ->assertOk()->assertJsonCount(2, 'lines');
});

test('deleting lines takes them, and their counts, from the own store only', function () {
    [$workspace, $machine] = lsrSetup();
    $theirs = LogStore::connection($workspace);
    lsrLine($theirs, $machine, 'a');
    lsrLine($theirs, $machine, 'b');
    lsrLine(LogStore::ours(), $machine, 'ours');

    $count = $this->actingAs($workspace->owner)->getJson(lsrUrl($workspace, '/logs/deletion?service=umami-umami-1'))
        ->assertOk()->assertJsonPath('count', 2)->json();

    $this->actingAs($workspace->owner)->deleteJson(lsrUrl($workspace), ['service' => 'umami-umami-1', 'up_to' => $count['upTo']])
        ->assertOk()->assertJson(['deleted' => 2, 'queued' => false]);

    expect(LogStore::entries($workspace)->count())->toBe(0)
        ->and(LogStore::counts($workspace)->count())->toBe(0)
        ->and(LogEntry::query()->pluck('message')->all())->toBe(['ours'])
        ->and(DB::table('log_counts')->sum('n'))->toEqual(1);
});

test('a background delete carries the workspace and runs on its store', function () {
    [$workspace, $machine] = lsrSetup();
    lsrLine(LogStore::connection($workspace), $machine, 'a');
    lsrLine(LogStore::ours(), $machine, 'ours');
    $upTo = (int) LogStore::entries($workspace)->max('id');
    LogStore::flush();

    (new DeleteLogLines($workspace->id, [$machine->id], ['service' => 'umami-umami-1', 'level' => null, 'q' => null, 'since' => null, 'ids' => null], $upTo))->handle();

    expect(LogStore::entries($workspace)->count())->toBe(0)
        ->and(LogEntry::query()->count())->toBe(1);
});

test('an own store that doesn\'t answer still opens the page, empty, and says why', function () {
    [$workspace, $machine, $store] = lsrSetup();
    lsrLine(LogStore::ours(), $machine, 'ours');
    lsrBreak($store);

    $this->actingAs($workspace->owner)->get(lsrUrl($workspace))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('logStore.external', true)
            ->where('logStore.status', 'unreachable')
            ->where('logStore.error', fn (?string $error) => $error !== null && $error !== '')
            ->has('lines', 0)
            ->where('histogram.buckets', fn ($buckets) => collect($buckets)->sum('lines') === 0));

    expect($store->fresh()->status)->toBe('unreachable')
        ->and($store->fresh()->last_error)->not->toBeNull();

    $this->actingAs($workspace->owner)->getJson(lsrUrl($workspace, '/logs/feed?after=0'))
        ->assertStatus(503)->assertJsonPath('message', 'Can’t reach your log database right now. Try again in a minute.');
    $this->actingAs($workspace->owner)->getJson(lsrUrl($workspace, '/logs/deletion?service=umami-umami-1'))
        ->assertStatus(503);
    $this->actingAs($workspace->owner)->deleteJson(lsrUrl($workspace), ['service' => 'umami-umami-1', 'up_to' => 10])
        ->assertStatus(503);
    expect(LogEntry::query()->count())->toBe(1);
});

test('the page clears an old unreachable once the store answers', function () {
    [$workspace, , $store] = lsrSetup();
    $store->forceFill(['status' => 'unreachable', 'last_error' => 'timed out'])->save();

    $this->actingAs($workspace->owner)->get(lsrUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page->where('logStore.status', 'ok'));
    expect($store->fresh()->status)->toBe('ok')
        ->and($store->fresh()->last_error)->toBeNull();
});

test('logs:prune prunes own stores that want it, skips those that don\'t, and gets past one that\'s down', function () {
    [$pruned, $prunedMachine] = lsrSetup(['prune' => true]);
    [$kept, $keptMachine] = lsrSetup(['prune' => false]);
    [$down, , $downStore] = lsrSetup(['prune' => true]);
    $old = now()->subDays(400)->getTimestampMs();
    lsrLine(LogStore::connection($pruned), $prunedMachine, 'old', ['logged_at' => $old]);
    lsrLine(LogStore::connection($pruned), $prunedMachine, 'new');
    lsrLine(LogStore::connection($kept), $keptMachine, 'old', ['logged_at' => $old]);
    lsrBreak($downStore);

    $this->artisan('logs:prune')->assertSuccessful();
    LogStore::flush();

    expect(LogStore::entries($pruned)->pluck('message')->all())->toBe(['new'])
        ->and(LogStore::counts($pruned)->count())->toBe(1)
        ->and(LogStore::entries($kept)->pluck('message')->all())->toBe(['old'])
        ->and($downStore->fresh()->status)->toBe('unreachable');
});

test('admin log volume never reads own stores, it counts them', function () {
    [$workspace, $machine] = lsrSetup();
    // Counts left in ours from before the move.
    lsrLine(LogStore::ours(), $machine, 'ours');

    $summary = LogVolume::summary();
    expect($summary['rows'])->toBe([])
        ->and($summary['ownStorage'])->toBe(1);
});

test('search_logs reads the own store, and says so when it can\'t be reached', function () {
    [$workspace, $machine, $store] = lsrSetup();
    $key = mcpKey($workspace);
    lsrLine(LogStore::connection($workspace), $machine, 'ERROR from their database', ['level' => 'error']);
    lsrLine(LogStore::ours(), $machine, 'left in ours');

    $all = mcpCall($workspace, $key, 'search_logs');
    expect($all['error'])->toBeFalse()
        ->and(collect($all['data']['entries'])->pluck('message')->all())->toBe(['ERROR from their database']);
    expect(collect(mcpCall($workspace, $key, 'search_logs', ['service' => 'Umami'])['data']['entries'])->pluck('message')->all())
        ->toBe(['ERROR from their database']);

    lsrBreak($store);
    $down = mcpCall($workspace, $key, 'search_logs');
    expect($down['error'])->toBeTrue()
        ->and($down['text'])->toContain('log database');
});
