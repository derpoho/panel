<?php

use App\Models\Machine;
use App\Models\MachineSample;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** Midday UTC plus half a minute: on a minute and a six-hour boundary. */
function usageNow(): Carbon
{
    return Carbon::parse('2026-10-05 12:00:30', 'UTC');
}

/** Send one telemetry batch for the machine. */
function sendTelemetry(Machine $machine, array $samples): void
{
    test()->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', ['envelopes' => [['v' => 1, 'kind' => 'telemetry', 'payload' => ['samples' => $samples]]]], ['X-Gateway-Secret' => 'test-gateway-secret'])
        ->assertOk();
}

/**
 * The machine page's props when it asks only for its usage history.
 *
 * @return array<string, mixed>
 */
function usageReload(Workspace $workspace, Machine $machine, string $range): array
{
    $url = 'https://'.$workspace->host().'/machines/'.$machine->id;
    $version = test()->actingAs($workspace->owner)->get($url)->viewData('page')['version'];

    return test()->actingAs($workspace->owner)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'workspace/MachineShow',
            'X-Inertia-Partial-Data' => 'history,range',
        ])
        ->get($url.'?range='.$range)
        ->assertOk()
        ->json('props');
}

test('telemetry folds machine readings into minutes with their average and peak', function () {
    $this->travelTo(usageNow());
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    $machine = Machine::factory()->create();
    $machine->forceFill(['uid' => (string) Str::uuid()])->save();
    $minute = usageNow()->startOfMinute()->getTimestamp();
    $disk = ['mount' => '/', 'used_bytes' => 40 << 30, 'free_bytes' => 60 << 30];

    sendTelemetry($machine, [
        ['ts' => $minute + 5, 'cpu_percent' => 10, 'mem_used' => 1 << 30, 'disks' => [$disk]],
        ['ts' => $minute + 20, 'cpu_percent' => 90, 'mem_used' => 3 << 30],
        // Per-service readings aren't the machine's.
        ['ts' => $minute + 20, 'service' => 'docker:abc', 'cpu_percent' => 100],
        // Too old to keep.
        ['ts' => $minute - 31 * 86400, 'cpu_percent' => 100],
    ]);
    // A clock running ahead counts as now.
    sendTelemetry($machine, [['ts' => $minute + 9999, 'cpu_percent' => 20, 'mem_used' => 2 << 30]]);

    $sample = MachineSample::query()->sole();
    expect($sample->ts)->toBe($minute)
        ->and($sample->samples)->toBe(3)
        ->and($sample->cpu)->toEqual(40)
        ->and($sample->cpu_max)->toEqual(90)
        ->and($sample->mem_used)->toBe(2 << 30)
        ->and($sample->mem_max)->toBe(3 << 30)
        ->and($sample->disk_percent)->toBe(40);
});

test('the machine page loads usage history only when asked, with gaps where nothing came in', function () {
    $this->travelTo(usageNow());
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $minute = usageNow()->startOfMinute()->getTimestamp();
    foreach ([[$minute, 40, 90], [$minute - 120, 10, 15], [$minute - 7200, 99, 99]] as [$ts, $cpu, $max]) {
        MachineSample::query()->create(['machine_id' => $machine->id, 'ts' => $ts, 'samples' => 6, 'cpu' => $cpu, 'cpu_max' => $max, 'mem_used' => 100, 'mem_max' => 200, 'disk_percent' => 50]);
    }

    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/machines/'.$machine->id)
        ->assertInertia(fn (Assert $page) => $page
            ->where('range', '24h')
            ->missing('history')
            // The stat tiles always get the last hour, a point a minute.
            ->has('recent', 60)
            ->where('recent.59.cpuMax', 90)
            ->etc());

    $props = usageReload($workspace, $machine, '1h');
    expect($props['range'])->toBe('1h')
        // A point a minute; the two-hour-old reading is outside the hour.
        ->and($props['history'])->toHaveCount(60)
        ->and($props['history'][59])->toEqual(['ts' => $minute, 'cpu' => 40, 'cpuMax' => 90, 'mem' => 100, 'memMax' => 200, 'disk' => 50])
        ->and($props['history'][58]['cpu'])->toBeNull()
        ->and($props['history'][57]['cpu'])->toEqual(10)
        ->and($props['history'][0]['cpu'])->toBeNull();
});

test('longer ranges combine minutes, keeping the highest peak', function () {
    $this->travelTo(usageNow());
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $midday = usageNow()->startOfMinute()->getTimestamp();
    foreach ([[$midday - 3600, 10, 100], [$midday - 60, 30, 35], [$midday, 5, 6]] as [$ts, $cpu, $max]) {
        MachineSample::query()->create(['machine_id' => $machine->id, 'ts' => $ts, 'samples' => 1, 'cpu' => $cpu, 'cpu_max' => $max]);
    }

    $history = usageReload($workspace, $machine, '30d')['history'];
    // Six-hour buckets: 06:00 to 12:00 holds the first two minutes.
    expect($history)->toHaveCount(120)
        ->and($history[119])->toMatchArray(['ts' => $midday, 'cpu' => 5])
        ->and($history[118])->toMatchArray(['ts' => $midday - 21600, 'cpu' => 20, 'cpuMax' => 100]);
});

test('usage history older than 30 days is pruned', function () {
    $machine = Machine::factory()->create();
    MachineSample::query()->create(['machine_id' => $machine->id, 'ts' => now()->subDays(31)->getTimestamp(), 'samples' => 1, 'cpu' => 1]);
    MachineSample::query()->create(['machine_id' => $machine->id, 'ts' => now()->subDays(29)->getTimestamp(), 'samples' => 1, 'cpu' => 1]);

    $this->artisan('logs:prune')->assertSuccessful();

    expect(MachineSample::query()->count())->toBe(1);
});
