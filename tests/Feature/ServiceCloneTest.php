<?php

use App\Enums\Plan;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A Pro workspace's machine, on a daemon that can clone, with an adopted
 * Postgres container, one only discovered, and port 18000 in use.
 *
 * @return array{0: Workspace, 1: Machine, 2: string}
 */
function cloneMachine(string $daemon = 'v0.1.1-pre.23'): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = Machine::factory()->for($workspace)->create();
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'daemon_version' => $daemon,
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [['port' => 18000, 'address' => '0.0.0.0', 'proto' => 'tcp']], 'services' => [
            ['key' => 'docker:aaa111bbb222', 'name' => 'shop-db-1', 'kind' => 'database', 'manager' => 'compose', 'status' => 'running', 'ports' => [5432], 'confidence' => 90, 'details' => ['managed' => 'true', 'image' => 'postgres:16']],
            ['key' => 'docker:ccc333ddd444', 'name' => 'shop-db-1-test', 'kind' => 'database', 'manager' => 'docker', 'status' => 'running', 'ports' => [], 'confidence' => 90, 'details' => []],
            ['key' => 'systemd:nginx.service', 'name' => 'nginx', 'kind' => 'web_server', 'manager' => 'systemd', 'status' => 'running', 'ports' => [80], 'confidence' => 90, 'details' => ['managed' => 'true']],
        ]],
    ])->save();

    return [$workspace, $machine, 'https://'.$workspace->host().'/machines/'.$machine->id.'/services/'];
}

function sentCloneJob(): array
{
    $request = collect(Http::recorded())->map(fn (array $pair) => $pair[0])->filter(fn ($r) => str_ends_with($r->url(), '/commands'))->last();

    return json_decode($request->body(), true)['command'];
}

test('the service page offers a clone with a free name and port', function () {
    [$workspace, , $url] = cloneMachine();

    $this->actingAs($workspace->owner)->get($url.'docker:aaa111bbb222')
        ->assertInertia(fn (Assert $page) => $page
            ->where('clone.unavailable', null)
            // shop-db-1-test is taken, and so is port 18000.
            ->where('clone.name', 'shop-db-1-test-2')
            ->where('clone.port', 18001));

    $this->actingAs($workspace->owner)->get($url.'systemd:nginx.service')
        ->assertInertia(fn (Assert $page) => $page->where('clone', null));
});

test('cloning sends the daemon the copy to make, and notes it in the activity', function () {
    [$workspace, , $url] = cloneMachine();

    $this->actingAs($workspace->owner)->postJson($url.'docker:aaa111bbb222/clone', [
        'name' => 'shop-db-copy', 'port' => 15432, 'copy_data' => true,
    ])->assertAccepted();

    $sent = sentCloneJob();
    expect($sent['job'])->toBe([
        'type' => 'service_clone', 'service' => 'docker:aaa111bbb222', 'name' => 'shop-db-copy',
        'port' => 15432, 'container_port' => null, 'domain' => null, 'copy_data' => true,
    ])
        ->and($sent['timeout_secs'])->toBe(3600)
        ->and(DaemonJob::query()->sole()->kind)->toBe('service_clone')
        ->and(Activity::query()->latest('id')->first())->action->toBe('service.clone')->meta->toMatchArray(['as' => 'shop-db-copy', 'data' => true]);

    // On a domain: a free port is picked for the proxy.
    $this->actingAs($workspace->owner)->postJson($url.'docker:aaa111bbb222/clone', [
        'name' => 'shop-db-web', 'domain' => 'https://Test.Example.com/', 'copy_data' => false,
    ])->assertAccepted();
    expect(sentCloneJob()['job'])->toMatchArray(['domain' => 'test.example.com', 'port' => 18001, 'copy_data' => false]);
});

test('names and ports already used on the machine are refused', function () {
    [$workspace, , $url] = cloneMachine();

    $this->actingAs($workspace->owner)->postJson($url.'docker:aaa111bbb222/clone', ['name' => 'shop-db-1-test', 'port' => 18000, 'copy_data' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['name', 'port']);
    $this->actingAs($workspace->owner)->postJson($url.'docker:aaa111bbb222/clone', ['name' => 'Shop_DB', 'port' => 80, 'copy_data' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['name', 'port']);
    expect(DaemonJob::query()->count())->toBe(0);
});

test('only adopted containers on a new enough daemon can be cloned, by people who manage machines', function () {
    [$workspace, $machine, $url] = cloneMachine();
    $payload = ['name' => 'copy', 'port' => 15432, 'copy_data' => false];

    $this->actingAs($workspace->owner)->postJson($url.'docker:ccc333ddd444/clone', $payload)
        ->assertConflict()->assertJson(['error' => 'Adopt this service first so ServerOS can control it.']);
    $this->actingAs($workspace->owner)->postJson($url.'systemd:nginx.service/clone', $payload)
        ->assertConflict()->assertJson(['error' => 'Only containers can be cloned for now.']);

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)->postJson($url.'docker:aaa111bbb222/clone', $payload)->assertForbidden();

    $machine->forceFill(['daemon_version' => 'v0.1.1-pre.22'])->save();
    $this->actingAs($workspace->owner)->postJson($url.'docker:aaa111bbb222/clone', $payload)
        ->assertConflict()->assertJsonFragment(['error' => 'This machine’s daemon is too old to clone services. It updates itself, or run “sudo serverosd update” on the server.']);
    expect(DaemonJob::query()->count())->toBe(0);
});

test('clone_service over MCP sends the same job', function () {
    [$workspace, $machine] = cloneMachine();
    $key = mcpKey($workspace);

    $result = mcpCall($workspace, $key, 'clone_service', ['machine' => $machine->id, 'service' => 'docker:aaa111bbb222', 'name' => 'shop-db-copy', 'port' => 15432, 'copy_data' => true]);
    expect($result['error'])->toBeFalse()
        ->and(sentCloneJob()['job'])->toMatchArray(['type' => 'service_clone', 'name' => 'shop-db-copy', 'port' => 15432, 'copy_data' => true])
        ->and(sentCloneJob()['timeout_secs'])->toBe(3600);

    expect(mcpCall($workspace, $key, 'clone_service', ['machine' => $machine->id, 'service' => 'docker:aaa111bbb222', 'name' => 'x-copy', 'copy_data' => true])['text'])->toContain('a port, or a domain')
        ->and(mcpCall($workspace, $key, 'clone_service', ['machine' => $machine->id, 'service' => 'docker:aaa111bbb222', 'name' => 'shop-db-1-test', 'port' => 15433, 'copy_data' => true])['text'])->toContain('already called that');
});
