<?php

use App\Http\Controllers\MachineUpdatesController;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** @return array{0: Workspace, 1: Machine, 2: string} */
function updatesMachine(): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()])->save();

    return [$workspace, $machine, 'https://'.$workspace->host()."/machines/{$machine->id}"];
}

test('checking, installing and following updates', function () {
    [$workspace, $machine, $url] = updatesMachine();

    $check = $this->actingAs($workspace->owner)->postJson("{$url}/updates/check")->assertAccepted()->json('jobId');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/commands') && $r['command']['job'] == ['type' => 'package_updates', 'apply' => false, 'security_only' => false]);

    DaemonJob::query()->whereKey($check)->update(['state' => 'succeeded', 'result' => json_encode([
        'manager' => 'apt',
        'upgradable' => ['openssl/noble-updates,noble-security 3.0.13-0ubuntu3.5 amd64 [upgradable from: 3.0.13-0ubuntu3.4]', 'curl/noble-updates 8.5.0-2ubuntu10.6 amd64 [upgradable from: 8.5.0-2ubuntu10.5]'],
        'security' => ['openssl/noble-updates,noble-security 3.0.13-0ubuntu3.5 amd64 [upgradable from: 3.0.13-0ubuntu3.4]'],
        'reboot_required' => true,
        'applied' => false,
    ])]);

    $this->actingAs($workspace->owner)->getJson("{$url}/updates/{$check}")->assertOk()->assertJson([
        'state' => 'succeeded',
        'report' => ['manager' => 'apt', 'rebootRequired' => true, 'packages' => [
            ['name' => 'openssl', 'from' => '3.0.13-0ubuntu3.4', 'to' => '3.0.13-0ubuntu3.5', 'security' => true],
            ['name' => 'curl', 'security' => false],
        ]],
    ]);

    $this->actingAs($workspace->owner)->postJson("{$url}/updates", ['security_only' => true])->assertAccepted();
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/commands') && ($r['command']['job']['apply'] ?? false) === true && $r['command']['job']['security_only'] === true);
    expect(Activity::query()->where('action', 'machine.update')->exists())->toBeTrue();

    // One install at a time.
    $this->actingAs($workspace->owner)->postJson("{$url}/updates", ['security_only' => false])->assertStatus(409);
});

test('restarting holds alerts, and viewers can do neither', function () {
    [$workspace, $machine, $url] = updatesMachine();

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)->postJson("{$url}/updates/check")->assertForbidden();
    $this->actingAs($viewer)->postJson("{$url}/reboot")->assertForbidden();

    $this->actingAs($workspace->owner)->postJson("{$url}/reboot")->assertAccepted();
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/commands') && $r['command']['job']['type'] === 'reboot');
    expect($machine->fresh()->inMaintenance())->toBeTrue();
});

test('dnf lines read as packages too', function () {
    expect(MachineUpdatesController::package('openssl.x86_64  1:3.0.7-28.el9  baseos', []))
        ->toBe(['name' => 'openssl', 'from' => null, 'to' => '1:3.0.7-28.el9', 'security' => false]);
});

test('picking which updates to install needs a daemon that can', function () {
    [$workspace, $machine, $url] = updatesMachine();
    $pick = ['security_only' => false, 'packages' => ['openssl', 'libc6:amd64']];

    $machine->forceFill(['daemon_version' => 'v0.1.0-pre.1'])->save();
    $this->actingAs($workspace->owner)->postJson("{$url}/updates", $pick)->assertStatus(409);

    $machine->forceFill(['daemon_version' => 'v0.1.1-pre.1'])->save();
    $this->actingAs($workspace->owner)->postJson("{$url}/updates", ['security_only' => false, 'packages' => ['--reinstall']])
        ->assertUnprocessable();
    $this->actingAs($workspace->owner)->postJson("{$url}/updates", $pick)->assertAccepted();
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/commands') && $r['command']['job'] == ['type' => 'package_install', 'packages' => ['openssl', 'libc6:amd64']]);
});
