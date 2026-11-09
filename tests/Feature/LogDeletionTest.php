<?php

use App\Enums\Plan;
use App\Jobs\DeleteLogLines;
use App\Models\Activity;
use App\Models\LogEntry;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use App\Support\Logs\LogDeletion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array{0: Workspace, 1: Machine} */
function delMachine(): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'Web One']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()])->save();

    return [$workspace, $machine];
}

/** @param  list<array<string, mixed>>  $lines */
function delShip(Machine $machine, array $lines): void
{
    test()->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', [
        'envelopes' => [['v' => 1, 'kind' => 'logs', 'payload' => ['lines' => $lines, 'dropped' => 0]]],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();
}

function delLine(string $line, array $overrides = []): array
{
    return ['service' => 'docker:aaa', 'name' => 'umami-umami-1', 'ts' => now()->getTimestampMs(), 'stream' => 'stdout', 'line' => $line, ...$overrides];
}

function delUrl(Workspace $workspace, string $path = '/logs'): string
{
    return 'https://'.$workspace->host().$path;
}

beforeEach(function () {
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
});

test('the count matches what the filters show, and the delete takes exactly those', function () {
    [$workspace, $machine] = delMachine();
    $minute = now()->startOfMinute()->getTimestampMs();
    delShip($machine, [
        delLine('token=hunter2 leaked', ['ts' => $minute + 1000]),
        delLine('error: token=hunter2 again', ['ts' => $minute + 2000]),
        delLine('all fine', ['ts' => $minute + 3000]),
        delLine('old token=hunter2', ['ts' => now()->subHours(3)->getTimestampMs()]),
        delLine('nginx token=hunter2', ['service' => 'systemd:nginx.service', 'name' => 'nginx.service', 'ts' => $minute + 4000]),
    ]);
    $filters = ['service' => 'umami-umami-1', 'range' => '1h', 'q' => 'hunter2'];

    $count = $this->actingAs($workspace->owner)->getJson(delUrl($workspace, '/logs/deletion?'.http_build_query($filters)))
        ->assertOk()->assertJsonPath('count', 2)->json();

    // A line that arrives while they decide is kept.
    delShip($machine, [delLine('new token=hunter2', ['ts' => $minute + 5000])]);

    $this->actingAs($workspace->owner)->deleteJson(delUrl($workspace), [...$filters, 'up_to' => $count['upTo'], 'since' => $count['since']])
        ->assertOk()->assertJson(['deleted' => 2, 'queued' => false]);

    expect(LogEntry::query()->pluck('message')->sort()->values()->all())
        ->toBe(['all fine', 'new token=hunter2', 'nginx token=hunter2', 'old token=hunter2']);

    // The chart: the minute holds what's left, by level.
    expect(DB::table('log_counts')->where('machine_id', $machine->id)->where('minute', $minute)->where('service', 'umami-umami-1')->pluck('n', 'level')->all())
        ->toEqual(['info' => 2]);

    $activity = Activity::query()->where('action', 'service.logs_delete')->sole();
    expect($activity->meta)->toBe(['count' => 2, 'service' => 'umami-umami-1', 'range' => '1h', 'search' => true])
        ->and(json_encode($activity->meta))->not->toContain('hunter2')
        ->and(ActivityLine::sentence($activity))->toContain('deleted log lines from Web One');
});

test('picked lines go, and a minute left empty leaves the chart', function () {
    [$workspace, $machine] = delMachine();
    $minute = now()->subMinutes(5)->startOfMinute()->getTimestampMs();
    delShip($machine, [
        delLine('secret one', ['ts' => $minute + 1000]),
        delLine('panic: secret two', ['ts' => $minute + 2000]),
        delLine('keep me', ['ts' => now()->getTimestampMs()]),
    ]);
    $ids = LogEntry::query()->where('message', 'like', '%secret%')->pluck('id')->all();

    $count = $this->actingAs($workspace->owner)->getJson(delUrl($workspace, '/logs/deletion?'.http_build_query(['ids' => $ids])))
        ->assertJsonPath('count', 2)->json();
    $this->actingAs($workspace->owner)->deleteJson(delUrl($workspace), ['ids' => $ids, 'up_to' => $count['upTo']])
        ->assertJson(['deleted' => 2]);

    expect(LogEntry::query()->pluck('message')->all())->toBe(['keep me'])
        ->and(DB::table('log_counts')->where('minute', $minute)->count())->toBe(0)
        ->and(Activity::query()->where('action', 'service.logs_delete')->sole()->meta)->toBe(['count' => 2, 'selected' => 2]);
});

test('another workspace’s lines can’t be picked', function () {
    [$workspace] = delMachine();
    [, $theirs] = delMachine();
    delShip($theirs, [delLine('not yours')]);
    $id = LogEntry::query()->sole()->id;

    $this->actingAs($workspace->owner)->deleteJson(delUrl($workspace), ['ids' => [$id], 'up_to' => $id])
        ->assertJson(['deleted' => 0]);
    expect(LogEntry::query()->count())->toBe(1);
});

test('filters alone must narrow by time or service', function () {
    [$workspace, $machine] = delMachine();
    delShip($machine, [delLine('a line')]);

    $this->actingAs($workspace->owner)->getJson(delUrl($workspace, '/logs/deletion?level=error'))
        ->assertUnprocessable()->assertJsonValidationErrors('scope');
    $this->actingAs($workspace->owner)->deleteJson(delUrl($workspace), ['up_to' => 999])
        ->assertUnprocessable()->assertJsonValidationErrors('scope');
    // A range needs the time it was counted at.
    $this->actingAs($workspace->owner)->deleteJson(delUrl($workspace), ['range' => '1h', 'up_to' => 999])
        ->assertJsonValidationErrors('since');
    expect(LogEntry::query()->count())->toBe(1);
});

test('only people who manage machines can delete, or see the button', function () {
    [$workspace, $machine] = delMachine();
    delShip($machine, [delLine('a line')]);
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    $this->actingAs($viewer)->get(delUrl($workspace))->assertInertia(fn (Assert $page) => $page->where('canDelete', false));
    $this->actingAs($workspace->owner)->get(delUrl($workspace))->assertInertia(fn (Assert $page) => $page->where('canDelete', true));
    $this->actingAs($viewer)->getJson(delUrl($workspace, '/logs/deletion?range=1h'))->assertForbidden();
    $this->actingAs($viewer)->deleteJson(delUrl($workspace), ['range' => '1h', 'since' => 0, 'up_to' => 999])->assertForbidden();
    expect(LogEntry::query()->count())->toBe(1);
});

test('a big delete runs in the background, in chunks', function () {
    [$workspace, $machine] = delMachine();
    $now = now()->getTimestampMs();
    $rows = array_map(fn (int $i) => ['machine_id' => $machine->id, 'service_key' => 'docker:aaa', 'service' => 'umami-umami-1', 'logged_at' => $now - $i, 'level' => 'info', 'stream' => 'stdout', 'message' => "line {$i}"], range(1, LogDeletion::SYNC_LIMIT + 1));
    foreach (array_chunk($rows, 1000) as $chunk) {
        LogEntry::query()->insert($chunk);
    }
    Queue::fake();

    $upTo = (int) LogEntry::query()->max('id');
    $this->actingAs($workspace->owner)->deleteJson(delUrl($workspace), ['service' => 'umami-umami-1', 'up_to' => $upTo])
        ->assertJson(['deleted' => LogDeletion::SYNC_LIMIT + 1, 'queued' => true]);
    expect(LogEntry::query()->count())->toBe(LogDeletion::SYNC_LIMIT + 1);

    Queue::assertPushed(DeleteLogLines::class, function (DeleteLogLines $job) {
        $job->handle();

        return true;
    });
    expect(LogEntry::query()->count())->toBe(0);
});
