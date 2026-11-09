<?php

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
});

/**
 * @return array{0: Workspace, 1: Machine, 2: string}
 */
function diskMachine(string $version = 'v0.1.1-pre.19'): array
{
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'daemon_version' => $version])->save();

    return [$workspace, $machine, 'https://'.$workspace->host().'/machines/'.$machine->id];
}

test('the machine page scans the disk and shows what\'s safe to clear', function () {
    [$workspace, $machine, $url] = diskMachine();

    $jobId = $this->actingAs($workspace->owner)->postJson("{$url}/disk-usage")
        ->assertAccepted()
        ->assertJson(['state' => 'queued', 'finished' => false])
        ->json('jobId');
    expect(DaemonJob::query()->findOrFail($jobId)->command['job'])->toBe(['type' => 'disk_usage']);

    DaemonJob::query()->whereKey($jobId)->update(['state' => 'succeeded', 'finished_at' => now(), 'result' => json_encode([
        'path' => '/', 'total' => 100, 'used' => 90, 'available' => 10, 'partial' => true,
        'dirs' => [['path' => '/var/cache/apt', 'bytes' => 300 * 1024 ** 2]], 'journal' => null, 'docker' => null,
    ])]);

    $this->actingAs($workspace->owner)->getJson("{$url}/disk-usage/{$jobId}")
        ->assertOk()
        ->assertJson(['state' => 'succeeded', 'finished' => true, 'partial' => true, 'available' => 10, 'dirs' => [['path' => '/var/cache/apt']]])
        ->assertJsonPath('suggestions.0.command', 'apt-get clean');
});

test('only people who manage machines can scan, and only daemons that know how', function () {
    [$workspace, , $url] = diskMachine();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)->postJson("{$url}/disk-usage")->assertForbidden();

    [$old, , $oldUrl] = diskMachine('v0.1.1-pre.18');
    $this->actingAs($old->owner)->postJson("{$oldUrl}/disk-usage")
        ->assertConflict()
        ->assertJsonFragment(['error' => 'Its ServerOS daemon is too old to say what’s using the disk. Update it from the machine’s page first.']);
    expect(DaemonJob::query()->count())->toBe(0);
});

test('a scan is only shown on its own machine', function () {
    [$workspace, $machine, $url] = diskMachine();
    $other = Machine::factory()->for($workspace)->create();
    $job = DaemonJob::query()->forceCreate(['id' => (string) Str::uuid(), 'machine_id' => $other->id, 'kind' => 'disk_usage', 'state' => 'queued']);
    $updates = DaemonJob::query()->forceCreate(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'package_updates', 'state' => 'queued']);

    $this->actingAs($workspace->owner)->getJson("{$url}/disk-usage/{$job->id}")->assertNotFound();
    $this->actingAs($workspace->owner)->getJson("{$url}/disk-usage/{$updates->id}")->assertNotFound();
});
