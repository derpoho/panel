<?php

use App\Models\Machine;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The panel knows about Docker from the machine's last scan, which is
 * hourly. A refusal asks the machine to look again, so someone who installs
 * Docker right after connecting a machine can deploy a few seconds later.
 */
function machineScannedBeforeDocker(): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'facts' => ['os' => 'ubuntu'],
        'inventory' => ['scanned_at' => now()->subMinute()->toIso8601String(), 'services' => [['key' => 'systemd:ssh.service']]],
    ])->save();

    return [$workspace, $machine, 'https://'.$workspace->host().'/machines/'.$machine->id];
}

/** @return list<string> the job types sent to the machine */
function sentJobTypes(): array
{
    return collect(Http::recorded())
        ->filter(fn (array $pair) => str_ends_with($pair[0]->url(), '/commands'))
        ->map(fn (array $pair) => json_decode($pair[0]->body(), true)['command']['job']['type'] ?? null)
        ->values()
        ->all();
}

test('a deploy refused for no Docker asks the machine to look again, once', function () {
    [$workspace, $machine, $url] = machineScannedBeforeDocker();
    $deploy = [
        'service' => 'crm', 'repo' => 'https://github.com/acme/crm.git', 'commit' => str_repeat('a', 40),
        'port' => 3000, 'health_path' => '/',
    ];

    $this->actingAs($workspace->owner)->postJson($url.'/deploy', $deploy)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['form' => 'checking the machine again now']);

    // Asking again while that scan runs reuses it.
    $this->actingAs($workspace->owner)->postJson($url.'/deploy', $deploy)->assertUnprocessable();

    expect(sentJobTypes())->toBe(['discover']);

    // Once the scan reports Docker, the deploy goes out.
    $machine->forceFill(['facts' => ['os' => 'ubuntu', 'docker_version' => '29.9.3']])->save();
    $this->actingAs($workspace->owner)->postJson($url.'/deploy', $deploy)->assertAccepted();

    expect(sentJobTypes())->toBe(['discover', 'deploy']);
});
