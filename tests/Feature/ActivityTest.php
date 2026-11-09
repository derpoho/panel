<?php

use App\Models\Activity;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ConsoleLock;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * An online machine with one managed container, and a fake gateway.
 *
 * @return array{0: Workspace, 1: Machine, 2: string}
 */
function activityMachine(): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
            ['key' => 'docker:abc123def456', 'name' => 'serveros-cache-redis-1', 'kind' => 'cache', 'manager' => 'compose',
                'status' => 'running', 'ports' => [6380], 'confidence' => 90, 'details' => ['managed' => 'true']],
        ]],
    ])->save();

    return [$workspace, $machine, 'https://'.$workspace->host().'/machines/'.$machine->id.'/services/'];
}

test('what people do to services is recorded and read back as sentences', function () {
    [$workspace, $machine, $url] = activityMachine();
    $workspace->owner->forceFill(['name' => 'Ada Lovelace'])->save();

    $this->actingAs($workspace->owner)->postJson($url.'docker:abc123def456/actions', ['action' => 'restart'])->assertAccepted();
    $this->actingAs($workspace->owner)->postJson($url.'docker:abc123def456/actions', ['action' => 'logs'])->assertAccepted();
    Activity::system($workspace, 'machine.offline', $machine->name, $machine);

    // Reading logs isn't an action worth a line.
    expect(Activity::query()->pluck('action')->all())->toBe(['service.restart', 'machine.offline']);

    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/activity')
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/Activity')
            ->where('entries.0.leads', true)
            ->where('entries.0.verb', 'stopped reporting')
            ->where('entries.0.user', null)
            ->where('entries.1.user.name', 'Ada Lovelace')
            ->where('entries.1.verb', 'restarted')
            ->where('entries.1.subject', 'serveros-cache-redis-1')
            ->where('entries.1.machine.id', $machine->id));

    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/activity?who=people')
        ->assertInertia(fn (Assert $page) => $page->has('entries', 1));
});

test('console commands are recorded word for word, shown only to people who can run them', function () {
    [$workspace, $machine, $url] = activityMachine();
    $this->mock(ConsoleLock::class, function ($mock) {
        $mock->shouldReceive('requiresSetup')->andReturn(false);
        $mock->shouldReceive('isUnlocked')->andReturn(true);
    });

    $this->actingAs($workspace->owner)->postJson($url.'docker:abc123def456/actions', ['action' => 'exec', 'command' => 'redis-cli FLUSHALL'])->assertAccepted();

    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/activity')
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.0.verb', 'ran a command in')
            ->where('entries.0.detail', 'redis-cli FLUSHALL'));

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)->get('https://'.$workspace->host().'/activity')
        ->assertInertia(fn (Assert $page) => $page->where('entries.0.detail', null));

    $this->actingAs(User::factory()->create())->get('https://'.$workspace->host().'/activity')->assertForbidden();
});

test('settings changes and removals are recorded', function () {
    [$workspace, $machine] = activityMachine();
    $base = 'https://'.$workspace->host().'/machines/'.$machine->id;

    $this->actingAs($workspace->owner)->patch($base, ['name' => 'web-prod', 'environment' => 'staging'])->assertRedirect();
    $this->actingAs($workspace->owner)->delete($base)->assertRedirect();

    expect(Activity::query()->orderBy('id')->get(['action', 'subject', 'meta'])->toArray())->toBe([
        ['action' => 'machine.rename', 'subject' => 'web-prod', 'meta' => ['from' => 'web-1']],
        ['action' => 'machine.environment', 'subject' => 'web-prod', 'meta' => ['to' => 'staging']],
        ['action' => 'machine.remove', 'subject' => 'web-prod', 'meta' => null],
    ]);
});
