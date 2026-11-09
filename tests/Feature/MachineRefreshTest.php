<?php

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

test('refresh asks the machine to scan now, once, for anyone in the workspace', function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'inventory' => ['scanned_at' => 1000, 'services' => [], 'listeners' => []]])->save();
    $url = 'https://'.$workspace->host()."/machines/{$machine->id}/refresh";

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    $first = $this->actingAs($viewer)->postJson($url)->assertAccepted()->assertJson(['scannedAt' => 1000])->json('jobId');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/commands') && $r['command']['job']['type'] === 'discover');

    // Clicking again while it runs reuses the same scan.
    expect($this->actingAs($workspace->owner)->postJson($url)->json('jobId'))->toBe($first)
        ->and(DaemonJob::query()->where('kind', 'discover')->count())->toBe(1);

    // An offline machine says so instead.
    $machine->forceFill(['last_seen_at' => now()->subHour()])->save();
    $this->actingAs($workspace->owner)->postJson($url)->assertOk()->assertJson(['jobId' => null])->assertJsonStructure(['note']);
});
