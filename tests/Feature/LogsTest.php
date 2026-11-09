<?php

use App\Enums\Plan;
use App\Models\Activity;
use App\Models\LogEntry;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Logs\LogLevel;
use App\Support\Logs\LogQuota;
use App\Support\Logs\LogVolume;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
});

/**
 * @return array{0: Workspace, 1: Machine}
 */
function logsMachine(Plan $plan = Plan::Pro): array
{
    $workspace = Workspace::factory()->onPlan($plan)->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'Web One']);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
            ['key' => 'docker:aaa', 'name' => 'umami-umami-1', 'kind' => 'app', 'manager' => 'docker', 'status' => 'running', 'ports' => []],
            ['key' => 'systemd:nginx.service', 'name' => 'nginx.service', 'kind' => 'web', 'manager' => 'systemd', 'status' => 'running', 'ports' => []],
        ]],
    ])->save();

    return [$workspace, $machine];
}

/** @param  list<array<string, mixed>>  $lines */
function shipLogs(Machine $machine, array $lines, array $extra = []): void
{
    test()->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', [
        'envelopes' => [
            ['v' => 1, 'kind' => 'logs', 'payload' => ['lines' => $lines, 'dropped' => 0]],
            ...$extra,
        ],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();
}

function logLine(string $line, array $overrides = []): array
{
    return ['service' => 'docker:aaa', 'name' => 'umami-umami-1', 'ts' => now()->getTimestampMs(), 'stream' => 'stdout', 'line' => $line, ...$overrides];
}

function logsUrl(Workspace $workspace, string $path = '/logs'): string
{
    return 'https://'.$workspace->host().$path;
}

test('shipped lines are stored with a level and a stable service name', function () {
    [, $machine] = logsMachine();

    shipLogs($machine, [
        logLine('GET / 200'),
        logLine('[ERROR] connect ECONNREFUSED 127.0.0.1:5432', ['stream' => 'stderr']),
        ['service' => 'systemd:nginx.service', 'ts' => now()->getTimestampMs(), 'stream' => 'journal', 'priority' => 4, 'line' => 'upstream slow'],
        ['service' => 'docker:aaa', 'line' => ['not a string']],
        logLine('   '),
    ]);

    $lines = LogEntry::query()->orderBy('id')->get();

    expect($lines)->toHaveCount(3)
        ->and($lines[0]->only(['service', 'service_key', 'level', 'stream']))->toBe(['service' => 'umami-umami-1', 'service_key' => 'docker:aaa', 'level' => 'info', 'stream' => 'stdout'])
        ->and($lines[1]->level)->toBe('error')
        // No name sent: taken from the machine's last scan.
        ->and($lines[2]->only(['service', 'level']))->toBe(['service' => 'nginx.service', 'level' => 'warn']);
});

test('an unknown envelope kind is skipped, not the whole batch', function () {
    [, $machine] = logsMachine();

    shipLogs($machine, [logLine('hello')], [['v' => 1, 'kind' => 'something_new', 'payload' => ['x' => 1]]]);

    expect(LogEntry::query()->count())->toBe(1);
});

test('levels are read the way people read them', function (string $line, ?int $priority, string $level) {
    expect(LogLevel::detect($line, $priority))->toBe($level);
})->with([
    ['plain line', null, 'info'],
    ['2026-10-05 12:00:00 ERROR boom', null, 'error'],
    ['level=warn msg="slow query"', null, 'warn'],
    ['{"level":50,"msg":"fail"}', null, 'error'],
    ['{"level":"debug","msg":"x"}', null, 'debug'],
    ['request finished with no error', null, 'info'],
    ['[warn] disk at 91%', null, 'warn'],
    ['error: could not bind :80', null, 'error'],
    ['GET /errors 200', null, 'info'],
    ['Traceback (most recent call last):', null, 'error'],
    ['anything', 2, 'error'],
    ['anything', 6, 'info'],
    ['time="2026-10-11T00:45:22Z" level=error msg="collecting stats for container"', 6, 'error'],
    ['level=info msg="ok"', 3, 'error'],
    ['debug: noisy', 5, 'debug'],
]);

test('the logs page filters by service, level and text', function () {
    [$workspace, $machine] = logsMachine();
    shipLogs($machine, [
        logLine('GET /health 200'),
        logLine('ERROR payment failed for order 42'),
        logLine('WARN retrying payment'),
        ['service' => 'systemd:nginx.service', 'name' => 'nginx.service', 'ts' => now()->getTimestampMs(), 'stream' => 'journal', 'priority' => 3, 'line' => 'payment upstream down'],
    ]);

    $this->actingAs($workspace->owner)->get(logsUrl($workspace))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/Logs')
            ->has('lines', 4)
            ->where('lines.0.message', 'GET /health 200')
            ->where('retentionDays', 14)
            ->where('services', fn ($services) => collect($services)->pluck('label')->all() === ['Nginx', 'Umami']));

    $this->actingAs($workspace->owner)->get(logsUrl($workspace, '/logs?service=umami-umami-1&q=PAYMENT'))
        ->assertInertia(fn (Assert $page) => $page->has('lines', 2));

    $this->actingAs($workspace->owner)->get(logsUrl($workspace, '/logs?level=error'))
        ->assertInertia(fn (Assert $page) => $page->has('lines', 2));

    $this->actingAs($workspace->owner)->get(logsUrl($workspace, '/logs?level=warn&service=umami-umami-1'))
        ->assertInertia(fn (Assert $page) => $page->has('lines', 2));

    // Wildcards in the search are literal.
    $this->actingAs($workspace->owner)->get(logsUrl($workspace, '/logs?q=%25'))
        ->assertInertia(fn (Assert $page) => $page->has('lines', 0));
});

test('the feed follows new lines, pages back, and shows a line in context', function () {
    [$workspace, $machine] = logsMachine();
    shipLogs($machine, array_map(fn ($i) => logLine("line {$i}"), range(1, 5)));
    $ids = LogEntry::query()->orderBy('id')->pluck('id');

    $this->actingAs($workspace->owner)->getJson(logsUrl($workspace, '/logs/feed?after='.$ids[2]))
        ->assertOk()
        ->assertJsonPath('lines.0.message', 'line 4')
        ->assertJsonCount(2, 'lines');

    $this->actingAs($workspace->owner)->getJson(logsUrl($workspace, '/logs/feed?before='.$ids[2]))
        ->assertJsonCount(2, 'lines')
        ->assertJsonPath('hasOlder', false);

    $this->actingAs($workspace->owner)->getJson(logsUrl($workspace, '/logs/feed?around='.$ids[2].'&q=nothing-matches'))
        ->assertJsonCount(5, 'lines');
});

test('logs stay inside their workspace', function () {
    [$workspace, $machine] = logsMachine();
    [$other] = logsMachine();
    shipLogs($machine, [logLine('secret-ish')]);
    $id = LogEntry::query()->value('id');

    $this->actingAs($other->owner)->get(logsUrl($other))
        ->assertInertia(fn (Assert $page) => $page->has('lines', 0));
    $this->actingAs($other->owner)->getJson(logsUrl($other, '/logs/feed?around='.$id))->assertNotFound();
    $this->actingAs($other->owner)->get(logsUrl($other, '/logs?machine='.$machine->id))->assertSessionHasErrors('machine');

    $stranger = User::factory()->create();
    $this->actingAs($stranger)->get(logsUrl($workspace))->assertForbidden();
});

test('old lines are pruned by the plan\'s retention', function () {
    [, $machine] = logsMachine(Plan::Essential);
    shipLogs($machine, [logLine('fresh')]);
    LogEntry::query()->insert([
        'machine_id' => $machine->id, 'service_key' => 'docker:aaa', 'service' => 'umami-umami-1',
        'logged_at' => now()->subDays(4)->getTimestampMs(), 'level' => 'info', 'stream' => 'stdout', 'message' => 'stale',
    ]);

    $this->artisan('logs:prune')->assertSuccessful();

    expect(LogEntry::query()->pluck('message')->all())->toBe(['fresh']);
});

test('with several machines the newest lines come back in order, across machines', function () {
    [$workspace, $web] = logsMachine();
    $db = Machine::factory()->for($workspace)->create(['name' => 'DB', 'uid' => (string) Str::uuid(), 'last_seen_at' => now()]);
    // Interleaved: web, db, web, db...
    foreach (range(1, 6) as $i) {
        shipLogs($i % 2 ? $web : $db, [logLine("line {$i}")]);
    }
    $ids = LogEntry::query()->orderBy('id')->pluck('id');

    $this->actingAs($workspace->owner)->get(logsUrl($workspace))
        ->assertInertia(fn ($page) => $page
            ->where('lines.0.message', 'line 1')
            ->where('lines.5.message', 'line 6'));

    $this->actingAs($workspace->owner)->getJson(logsUrl($workspace, '/logs/feed?before='.$ids[4]))
        ->assertJsonPath('lines.0.message', 'line 1')
        ->assertJsonPath('lines.3.message', 'line 4')
        ->assertJsonCount(4, 'lines');
});

test('the service filter offers every service a machine has logged under', function () {
    [$workspace, $machine] = logsMachine();
    shipLogs($machine, [
        logLine('a', ['service' => 'docker:old', 'name' => 'old-worker-1']),
        logLine('b'),
        logLine('c', ['service' => 'systemd:cron.service', 'name' => 'cron.service']),
        logLine('d'),
    ]);

    $this->actingAs($workspace->owner)->get(logsUrl($workspace))
        ->assertInertia(fn ($page) => $page->where('services', fn ($services) => collect($services)->pluck('name')->contains('old-worker-1')
            && collect($services)->pluck('name')->contains('cron.service')));
});

test('the chart counts lines per minute as they arrive, by level', function () {
    $this->travelTo(now()->startOfMinute()->addSeconds(30));
    [$workspace, $machine] = logsMachine();

    shipLogs($machine, [logLine('GET / 200'), logLine('GET /a 200'), logLine('[ERROR] boom')]);
    shipLogs($machine, [logLine('GET /b 200'), logLine('[WARN] slow')]);

    expect(DB::table('log_counts')->where('machine_id', $machine->id)->pluck('n', 'level')->all())
        ->toEqual(['info' => 3, 'error' => 1, 'warn' => 1]);

    $this->actingAs($workspace->owner)->get(logsUrl($workspace))->assertInertia(fn (Assert $page) => $page
        ->where('histogram.bucketMinutes', 1)
        ->has('histogram.buckets', 60)
        ->where('histogram.buckets.59.lines', 5)
        ->where('histogram.buckets.59.warn', 1)
        ->where('histogram.buckets.59.error', 1));

    // Filters the chart too: errors only, and wider buckets for a week.
    $this->actingAs($workspace->owner)->get(logsUrl($workspace, '/logs?level=error&range=7d'))->assertInertia(fn (Assert $page) => $page
        ->where('histogram.bucketMinutes', 120)
        ->where('histogram.buckets', fn ($buckets) => collect($buckets)->sum('lines') === 1));
});

test('counting the lines stored before the chart replaces counts rather than adding to them', function () {
    [, $machine] = logsMachine();
    $at = now()->subHours(3)->startOfMinute()->getTimestampMs();
    foreach (['one', 'two', '[ERROR] three'] as $message) {
        LogEntry::query()->insert([
            'machine_id' => $machine->id, 'service_key' => 'docker:aaa', 'service' => 'umami-umami-1',
            'logged_at' => $at + 1000, 'level' => LogLevel::detect($message, null), 'stream' => 'stdout', 'message' => $message,
        ]);
    }

    $this->artisan('logs:backfill-counts')->assertSuccessful();
    $this->artisan('logs:backfill-counts')->assertSuccessful();

    expect(DB::table('log_counts')->where('minute', $at)->pluck('n', 'level')->all())->toEqual(['info' => 2, 'error' => 1]);
});

test('pruning old lines prunes their counts too', function () {
    [, $machine] = logsMachine(Plan::Essential);
    shipLogs($machine, [logLine('fresh')]);
    DB::table('log_counts')->insert(['machine_id' => $machine->id, 'minute' => now()->subDays(4)->startOfMinute()->getTimestampMs(), 'service' => 'umami-umami-1', 'level' => 'info', 'n' => 9]);

    $this->artisan('logs:prune')->assertSuccessful();

    expect(DB::table('log_counts')->sum('n'))->toEqual(1);
});

test('lines past the plan\'s daily allowance are dropped and shown on the logs page', function () {
    [$workspace, $machine] = logsMachine(Plan::Essential);
    Cache::put('log-quota:'.$workspace->id.':'.now('UTC')->format('Ymd').':kept', Plan::Essential->logLinesPerDay() - 2, now()->addDay());

    shipLogs($machine, [logLine('one'), logLine('two'), logLine('three'), logLine('four')]);

    expect(LogEntry::query()->pluck('message')->all())->toBe(['one', 'two'])
        ->and(LogQuota::today($workspace))->toMatchArray(['kept' => Plan::Essential->logLinesPerDay(), 'dropped' => 2]);

    $this->actingAs($workspace->owner)->get(logsUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page
            ->where('quota.dropped', 2)
            ->where('quota.limit', Plan::Essential->logLinesPerDay())
            ->whereNot('quota.fullAt', null));

    // Tomorrow starts afresh.
    $this->travel(1)->days();
    shipLogs($machine, [logLine('five')]);
    expect(LogEntry::query()->where('message', 'five')->exists())->toBeTrue()
        ->and(LogQuota::today($workspace)['dropped'])->toBe(0);
});

test('a service whose logs are muted has its lines dropped, and they don\'t count', function () {
    [$workspace, $machine] = logsMachine();
    $url = 'https://'.$workspace->host().'/machines/'.$machine->id.'/services/docker:aaa/logs';

    $this->actingAs($workspace->owner)->put($url, ['muted' => true])->assertRedirect();
    expect($machine->fresh()->log_muted_services)->toBe(['container:umami-umami-1'])
        ->and(Activity::query()->latest('id')->first()->action)->toBe('service.logs_mute');

    shipLogs($machine->fresh(), [logLine('muted'), logLine('kept', ['service' => 'systemd:nginx.service', 'name' => 'nginx.service'])]);
    expect(LogEntry::query()->pluck('message')->all())->toBe(['kept'])
        ->and(LogQuota::today($workspace)['kept'])->toBe(1);

    $this->actingAs($workspace->owner)->put($url, ['muted' => false])->assertRedirect();
    shipLogs($machine->fresh(), [logLine('back')]);
    expect(LogEntry::query()->where('message', 'back')->exists())->toBeTrue()
        ->and($machine->fresh()->log_muted_services)->toBeNull();
});

test('only people who manage machines can mute a service\'s logs', function () {
    [$workspace, $machine] = logsMachine();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    $this->actingAs($viewer)->put('https://'.$workspace->host().'/machines/'.$machine->id.'/services/docker:aaa/logs', ['muted' => true])
        ->assertForbidden();
    expect($machine->fresh()->log_muted_services)->toBeNull();
});

test('admin stats list the workspaces sending the most log lines', function () {
    [$workspace, $machine] = logsMachine();
    shipLogs($machine, [logLine('a'), logLine('b'), logLine('c', ['service' => 'systemd:nginx.service', 'name' => 'nginx.service'])]);

    expect(LogVolume::summary()['rows'])->toBe([[
        'id' => $workspace->id, 'name' => $workspace->name, 'plan' => 'pro', 'lines' => 3,
        'top' => 'umami-umami-1', 'topLines' => 2, 'limit' => Plan::Pro->logLinesPerDay(), 'kept' => 3, 'dropped' => 0,
    ]]);
});

test('logs:relevel moves journald lines stored as info to the level they show', function () {
    [, $machine] = logsMachine();
    $at = now()->startOfMinute()->getTimestampMs();
    $line = fn (string $message) => ['machine_id' => $machine->id, 'service_key' => 'systemd:docker.service', 'service' => 'docker.service', 'logged_at' => $at, 'level' => 'info', 'stream' => 'journal', 'message' => $message];
    LogEntry::query()->insert([$line('time="x" level=error msg="no metrics"'), $line('started fine')]);
    DB::table('log_counts')->insert(['machine_id' => $machine->id, 'minute' => $at, 'service' => 'docker.service', 'level' => 'info', 'n' => 2]);

    $this->artisan('logs:relevel')->assertSuccessful();

    expect(LogEntry::query()->orderBy('id')->pluck('level')->all())->toBe(['error', 'info'])
        ->and(DB::table('log_counts')->where('minute', $at)->pluck('n', 'level')->all())->toEqual(['info' => 1, 'error' => 1]);
});
