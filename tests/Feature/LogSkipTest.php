<?php

use App\Enums\Plan;
use App\Models\Activity;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use App\Support\Logs\LogSkipRules;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['ok' => true])]);
});

/** @return array{0: Workspace, 1: Machine, 2: string} */
function skipMachine(string $version = 'v0.1.1-pre.22'): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'Box']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'certificate_serial' => bin2hex(random_bytes(6)), 'daemon_version' => $version, 'last_seen_at' => now()])->save();

    return [$workspace, $machine, 'https://'.$workspace->host().'/machines/'.$machine->id];
}

test('saving skip rules tidies them, tells the daemon and records it', function () {
    [$workspace, $machine, $url] = skipMachine();

    $this->actingAs($workspace->owner)->put("{$url}/log-skip", [
        'services' => ['wings', ' ptero-* ', '', 'wings'],
        'patterns' => [['service' => '', 'pattern' => 'password=\S+'], ['service' => 'nginx*', 'pattern' => '^\d+\.'], ['service' => 'x', 'pattern' => '  ']],
    ])->assertSessionHasNoErrors();

    $rules = ['services' => ['wings', 'ptero-*'], 'patterns' => [['service' => null, 'pattern' => 'password=\S+'], ['service' => 'nginx*', 'pattern' => '^\d+\.']]];
    Http::assertSent(fn ($request) => str_ends_with($request->url(), "/machines/{$machine->uid}/control")
        && $request->data()['control']['type'] === 'reconfigure'
        && $request->data()['control']['log_skip'] === $rules);
    expect($machine->fresh()->log_skip)->toBe($rules);

    $activity = Activity::query()->where('action', 'machine.log_skip')->sole();
    expect($activity->meta)->toBe(['services' => 2, 'patterns' => 2])
        ->and(ActivityLine::sentence($activity))->toContain('changed which logs never leave Box');

    $this->actingAs($workspace->owner)->get("{$url}/settings")
        ->assertInertia(fn (Assert $page) => $page->where('logSkip.rules', $rules)->where('logSkip.available', true));
});

test('patterns the daemon can’t run are turned down', function (string $pattern) {
    [$workspace, $machine, $url] = skipMachine();

    $this->actingAs($workspace->owner)->put("{$url}/log-skip", ['services' => [], 'patterns' => [['service' => null, 'pattern' => $pattern]]])
        ->assertSessionHasErrors('patterns.0.pattern');
    Http::assertNothingSent();
    expect($machine->fresh()->log_skip)->toBeNull();
})->with(['(unclosed', 'foo(?=bar)', '(?<!x)y', '(a)\1', str_repeat('a', 301)]);

test('an old daemon can’t be given rules', function () {
    [$workspace, $machine, $url] = skipMachine('v0.1.1-pre.21');

    $this->actingAs($workspace->owner)->put("{$url}/log-skip", ['services' => ['wings'], 'patterns' => []])
        ->assertSessionHasErrors('services');
    Http::assertNothingSent();
    $this->actingAs($workspace->owner)->get("{$url}/settings")
        ->assertInertia(fn (Assert $page) => $page->where('logSkip.available', false)->where('logSkip.rules', LogSkipRules::none()));
});

test('the daemon reports its rules when it connects', function () {
    [, $machine] = skipMachine();
    $hello = fn (array $extra) => $this->postJson('/api/gateway/hello', [
        'serial' => $machine->certificate_serial,
        'hello' => ['machine_id' => $machine->uid, 'daemon_version' => 'v0.1.1-pre.22', 'supported_majors' => [1], 'facts' => ['hostname' => 'box'], ...$extra],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();

    $hello(['log_skip' => ['services' => ['wings'], 'patterns' => [['service' => null, 'pattern' => 'x'], ['bad' => true]]]]);
    expect($machine->fresh()->log_skip)->toBe(['services' => ['wings'], 'patterns' => [['service' => null, 'pattern' => 'x']]]);

    $hello([]);
    expect($machine->fresh()->log_skip['services'])->toBe(['wings']);
});
