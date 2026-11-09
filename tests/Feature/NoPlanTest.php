<?php

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\DaemonJobQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A workspace whose trial ended without a plan, with an online machine
 * running an adopted container.
 *
 * @return array{0: Workspace, 1: Machine, 2: string}
 */
function noPlanMachine(): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $workspace->forceFill(['plan' => null, 'billing_interval' => null, 'trial_ends_at' => now()->subDay()])->save();
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
            ['key' => 'docker:aaa111aaa111', 'name' => 'shop', 'kind' => 'app', 'manager' => 'docker', 'status' => 'running', 'ports' => [], 'confidence' => 90, 'details' => ['managed' => 'true']],
        ]],
    ])->save();

    return [$workspace, $machine, 'https://'.$workspace->host().'/machines/'.$machine->id];
}

test('without a plan, deploys and the console are refused but starting and stopping still work', function () {
    [$workspace, $machine, $url] = noPlanMachine();
    $owner = $workspace->owner;

    $this->actingAs($owner)->postJson($url.'/deploy', ['template' => 'postgres', 'service' => 'db'])
        ->assertStatus(409)
        ->assertJsonPath('error', fn (string $e) => str_contains($e, 'Deploys need a plan'));

    $this->actingAs($owner)->postJson($url.'/services/docker:aaa111aaa111/actions', ['action' => 'restart'])->assertAccepted();

    expect(DaemonJob::query()->pluck('kind')->all())->toBe(['service_action']);
});

test('the queue itself refuses deploys, rollbacks and commands without a plan', function () {
    [$workspace, $machine] = noPlanMachine();
    $machine = $machine->fresh();
    $queue = app(DaemonJobQueue::class);

    foreach (['deploy', 'rollback', 'service_exec'] as $kind) {
        expect(fn () => $queue->queue($machine, $workspace->owner, $kind, ['type' => $kind], 'shop'))
            ->toThrow(RuntimeException::class, 'need');
    }
    expect($queue->unavailable($machine, 'service_exec'))->toContain('The console needs a plan')
        ->and($queue->unavailable($machine, 'service_action'))->toBeNull()
        ->and($queue->unavailable($machine))->toBeNull()
        ->and(DaemonJob::query()->count())->toBe(0);

    // With a plan, they go through.
    $workspace->forceFill(['plan' => 'pro', 'billing_interval' => 'monthly'])->save();
    expect($queue->unavailable($machine->fresh(), 'deploy'))->toBeNull();
});
