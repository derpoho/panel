<?php

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Client\Request as GatewayRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * @return array{0: Workspace, 1: Machine, 2: string}
 */
function retryMachine(): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()])->save();

    return [$workspace, $machine, 'https://'.$workspace->host().'/machines/'.$machine->id];
}

test('a deploy keeps its command encrypted and a retry sends it again unchanged', function () {
    [$workspace, $machine, $url] = retryMachine();

    $this->actingAs($workspace->owner)
        ->postJson($url.'/deploy', ['template' => 'postgres', 'service' => 'db'])
        ->assertAccepted();

    $first = DaemonJob::query()->where('machine_id', $machine->id)->firstOrFail();
    $password = $first->command['job']['env']['POSTGRES_PASSWORD'];

    // Stored encrypted, never in plain text, and never sent to the page.
    expect($first->getRawOriginal('command'))->not->toContain($password)
        ->and($first->toArray())->not->toHaveKey('command');

    $first->forceFill(['state' => 'failed', 'finished_at' => now()])->save();

    $retryId = $this->actingAs($workspace->owner)
        ->postJson($url.'/jobs/'.$first->id.'/retry')
        ->assertAccepted()
        ->json('jobId');

    expect($retryId)->not->toBe($first->id);

    // The same password goes out again, so the database volume still matches.
    $sent = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (GatewayRequest $request) => str_ends_with($request->url(), '/commands'))
        ->map(fn (GatewayRequest $request) => json_decode($request->body(), true))
        ->values();

    expect($sent)->toHaveCount(2)
        ->and($sent[1]['id'])->toBe($retryId)
        ->and($sent[1]['command']['job']['env']['POSTGRES_PASSWORD'])->toBe($password);
});

test('running deploys can only be retried or abandoned once they have gone quiet', function () {
    [$workspace, $machine, $url] = retryMachine();

    $job = DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'deploy', 'service' => 'web',
        'state' => 'running', 'log' => [], 'command' => ['job' => ['type' => 'deploy', 'service' => 'web']],
    ]);

    $this->actingAs($workspace->owner)->postJson($url.'/jobs/'.$job->id.'/retry')->assertStatus(409);

    $this->travel(DaemonJob::STALE_AFTER_MINUTES + 1)->minutes();
    $machine->forceFill(['last_seen_at' => now()])->save();

    $this->actingAs($workspace->owner)->postJson($url.'/jobs/'.$job->id.'/retry')->assertAccepted();
    expect($job->fresh()->state)->toBe('cancelled');
});

test('stopping waiting marks a stuck job cancelled without touching the machine', function () {
    [$workspace, $machine, $url] = retryMachine();

    $job = DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'deploy', 'service' => 'redis',
        'state' => 'queued', 'log' => [],
    ]);

    // Jobs with no saved command can't be retried, only abandoned.
    $this->actingAs($workspace->owner)->postJson($url.'/jobs/'.$job->id.'/retry')->assertUnprocessable();

    $this->actingAs($workspace->owner)->postJson($url.'/jobs/'.$job->id.'/abandon')->assertOk();

    expect($job->fresh()->state)->toBe('cancelled')
        ->and($job->fresh()->error['message'])->toContain('Stopped waiting');
    expect(Activity::query()->sole()->only(['action', 'subject', 'meta']))->toBe(['action' => 'job.abandon', 'subject' => 'redis', 'meta' => ['kind' => 'deploy']]);
    Http::assertNothingSent();

    $this->actingAs($workspace->owner)->postJson($url.'/jobs/'.$job->id.'/abandon')->assertStatus(409);
});

test('only machine managers can retry, and only jobs on that machine', function () {
    [$workspace, $machine, $url] = retryMachine();
    $other = Machine::factory()->create();
    $foreign = DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $other->id, 'kind' => 'deploy', 'service' => 'x',
        'state' => 'failed', 'log' => [],
    ]);
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    $this->actingAs($workspace->owner)->postJson($url.'/jobs/'.$foreign->id.'/retry')->assertNotFound();
    $this->actingAs($viewer)->postJson($url.'/jobs/'.$foreign->id.'/abandon')->assertNotFound();

    $mine = DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'deploy', 'service' => 'y',
        'state' => 'queued', 'log' => [],
    ]);
    $this->actingAs($viewer)->postJson($url.'/jobs/'.$mine->id.'/abandon')->assertForbidden();
});

test('job updates add to the log, and every daemon state is understood', function () {
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    $machine = Machine::factory()->create();
    $machine->forceFill(['uid' => (string) Str::uuid()])->save();
    $job = DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'deploy', 'service' => 'web',
        'state' => 'queued', 'log' => [],
    ]);
    $ingest = fn (array $payload) => $this->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', [
        'envelopes' => [['v' => 1, 'kind' => 'job_update', 'payload' => ['job_id' => $job->id, ...$payload]]],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();

    $ingest(['state' => 'accepted']);
    expect($job->fresh()->state)->toBe('queued');

    $ingest(['state' => 'running', 'phase' => 'fetch', 'progress' => 15, 'log' => ['cloning']]);
    $ingest(['state' => 'running', 'phase' => 'build', 'progress' => 40, 'log' => []]);
    $ingest(['state' => 'running', 'log' => ['step 1/4', 'step 2/4']]);
    $ingest(['state' => 'timed_out', 'log' => []]);

    $job->refresh();
    expect($job->log)->toBe(['cloning', 'step 1/4', 'step 2/4'])
        ->and($job->phase)->toBe('build')
        ->and($job->state)->toBe('timed_out')
        ->and($job->finished_at)->not->toBeNull();

    // Finished jobs ignore late updates.
    $ingest(['state' => 'running', 'log' => ['late']]);
    expect($job->fresh()->log)->toHaveCount(3);
});

test('per-service telemetry is kept as the latest snapshot for each service', function () {
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    $machine = Machine::factory()->create();
    $machine->forceFill(['uid' => (string) Str::uuid()])->save();
    $send = fn (array $samples) => $this->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', [
        'envelopes' => [['v' => 1, 'kind' => 'telemetry', 'payload' => ['samples' => $samples]]],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();

    $send([
        ['ts' => 100, 'service' => null, 'cpu_percent' => 40.4, 'mem_used' => 2147483648],
        ['ts' => 100, 'service' => 'docker:abc123def456', 'cpu_percent' => 3.21, 'mem_used' => 9961472, 'mem_total' => 8_000_000_000, 'process_count' => 6, 'started_at' => 90, 'restarts' => 1],
        ['ts' => 100, 'service' => 'systemd:nginx.service', 'cpu_percent' => 0.5, 'mem_used' => 1000, 'process_count' => 2],
    ]);

    $machine->refresh();
    expect($machine->cpu_percent)->toBe(40)
        ->and($machine->service_stats)->toHaveKeys(['docker:abc123def456', 'systemd:nginx.service'])
        ->and($machine->service_stats['docker:abc123def456'])->toMatchArray(['cpu' => 3.21, 'memUsed' => 9961472, 'pids' => 6, 'startedAt' => 90, 'restarts' => 1]);

    // Machine-only batches leave the services alone; a new service batch replaces them.
    $send([['ts' => 200, 'service' => null, 'cpu_percent' => 10]]);
    expect($machine->fresh()->service_stats)->toHaveCount(2);

    $send([['ts' => 300, 'service' => 'systemd:nginx.service', 'cpu_percent' => 1]]);
    expect($machine->fresh()->service_stats)->toHaveCount(1)->toHaveKey('systemd:nginx.service');
});
