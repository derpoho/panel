<?php

use App\Enums\Plan;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Health\MachineWatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A machine running "shop", deployed from code twice: the old version is
 * still there (stopped by the deploy), the new one runs.
 *
 * @return array{0: Workspace, 1: Machine, 2: string}
 */
function shopMachine(string $oldStatus = 'running'): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'environment' => 'production']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()])->save();

    foreach ([str_repeat('a', 40), str_repeat('b', 40)] as $i => $commit) {
        DaemonJob::query()->create([
            'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'deploy', 'service' => 'shop', 'state' => 'succeeded', 'log' => [],
            'command' => ['job' => ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => $commit]],
            'created_at' => now()->subMinutes(10 - $i),
        ]);
    }

    shopInventory($machine, $oldStatus);

    return [$workspace, $machine, 'https://'.$workspace->host()];
}

function shopInventory(Machine $machine, string $oldStatus, string $newStatus = 'running'): void
{
    $svc = fn ($key, $name, $status) => ['key' => $key, 'name' => $name, 'kind' => 'app', 'manager' => 'docker', 'status' => $status, 'ports' => [], 'confidence' => 90, 'details' => ['managed' => 'true']];
    $machine->forceFill([
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
            $svc('docker:111111111111', '/serveros-shop-'.str_repeat('a', 12), $oldStatus),
            $svc('docker:222222222222', '/serveros-shop-'.str_repeat('b', 12), $newStatus),
            $svc('docker:333333333333', 'postgres', 'running'),
        ]],
        'service_stats' => [],
        'last_seen_at' => now(),
    ])->save();
}

test('a deployed app has one page that stays put across versions', function () {
    [$workspace, $machine, $base] = shopMachine();

    // The live version, under the app's own name and address.
    $this->actingAs($workspace->owner)->get("{$base}/machines/{$machine->id}/services/app:shop")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('service.key', 'docker:222222222222')
            ->where('service.app', 'shop'));

    // Links to any version's container lead there.
    $this->actingAs($workspace->owner)->get("{$base}/machines/{$machine->id}/services/docker:111111111111")
        ->assertRedirect("/machines/{$machine->id}/services/app:shop");

    // Lists show the app once, without the retired version.
    $this->actingAs($workspace->owner)->get("{$base}/machines/{$machine->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('machine.inventory.services', 2)
            ->where('machine.inventory.services.0.app', 'shop')
            ->where('machine.inventory.services.1.name', 'postgres'));

    // Actions on the app go to whichever container is live.
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
    $this->actingAs($workspace->owner)->postJson("{$base}/machines/{$machine->id}/services/app:shop/actions", ['action' => 'restart'])->assertAccepted();
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/commands') && $r['command']['job']['service'] === 'docker:222222222222');
});

test('the version a deploy stops is not an outage', function () {
    Notification::fake();
    [$workspace, $machine] = shopMachine();
    $watch = fn () => app(MachineWatcher::class)->run();

    expect($watch())->toBe(0);

    // The deploy stops the old version: nothing to say.
    shopInventory($machine, 'stopped');
    expect($watch())->toBe(0)
        ->and(Activity::query()->where('action', 'service.down')->exists())->toBeFalse();

    // The live version stopping is.
    shopInventory($machine, 'stopped', 'stopped');
    expect($watch())->toBe(1);
});

test('needs attention items can be marked as read until they happen again', function () {
    Notification::fake();
    [$workspace, $machine, $base] = shopMachine();
    $watch = fn () => app(MachineWatcher::class)->run();
    $watch();
    shopInventory($machine, 'stopped', 'stopped');
    $watch();

    $issues = fn (User $user) => $this->actingAs($user)->get("{$base}/")->viewData('page')['props']['issues'];
    $down = collect($issues($workspace->owner))->first(fn ($i) => str_contains($i['title'], 'stopped on web-1'));
    expect($down)->not->toBeNull();

    // Viewers can't hide things from everyone else.
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)->post("{$base}/issues/dismiss", ['ids' => [$down['id']]])->assertForbidden();

    $this->actingAs($workspace->owner)->post("{$base}/issues/dismiss", ['ids' => [$down['id']]])->assertRedirect();
    expect(collect($issues($workspace->owner))->pluck('id'))->not->toContain($down['id'])
        ->and(collect($issues($viewer))->pluck('id'))->not->toContain($down['id']);

    // It comes back if it recovers and stops again.
    shopInventory($machine, 'stopped');
    $watch();
    $this->travel(2)->minutes();
    shopInventory($machine, 'stopped', 'stopped');
    $watch();
    expect(collect($issues($workspace->owner))->pluck('id'))->toContain($down['id']);
});

test('services can be given names of their own, which outlive a redeploy', function () {
    [$workspace, $machine, $base] = shopMachine();

    $this->actingAs($workspace->owner)->put("{$base}/machines/{$machine->id}/services/app:shop/label", ['label' => 'Online shop'])->assertRedirect();
    $this->actingAs($workspace->owner)->put("{$base}/machines/{$machine->id}/services/docker:333333333333/label", ['label' => 'Main database'])->assertRedirect();

    // A new version keeps the name: it's kept by the app, not the container.
    shopInventory($machine->fresh(), 'stopped');
    $this->actingAs($workspace->owner)->get("{$base}/machines/{$machine->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('machine.inventory.services.0.label', 'Online shop')
            ->where('machine.inventory.services.1.label', 'Main database'));
    expect(Activity::query()->where('action', 'service.rename')->count())->toBe(2);

    // Clearing it goes back to the found name; viewers can't rename.
    $this->actingAs($workspace->owner)->put("{$base}/machines/{$machine->id}/services/app:shop/label", ['label' => ''])->assertRedirect();
    expect($machine->fresh()->service_labels)->toBe(['container:postgres' => 'Main database']);

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)->put("{$base}/machines/{$machine->id}/services/app:shop/label", ['label' => 'x'])->assertForbidden();
});

test('services and machines can have icons of their own', function () {
    Storage::fake('public');
    [$workspace, $machine, $base] = shopMachine();

    $this->actingAs($workspace->owner)->post("{$base}/machines/{$machine->id}/services/app:shop/icon", ['icon' => UploadedFile::fake()->image('shop.png', 64, 64)])->assertRedirect();
    $this->actingAs($workspace->owner)->post("{$base}/machines/{$machine->id}/icon", ['icon' => UploadedFile::fake()->image('box.webp', 64, 64)])->assertRedirect();

    $path = $machine->fresh()->service_icons['app:shop'];
    Storage::disk('public')->assertExists($path);
    $this->actingAs($workspace->owner)->get("{$base}/machines/{$machine->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('machine.iconUrl', Storage::disk('public')->url($machine->fresh()->icon_path))
            ->where('machine.inventory.services.0.icon', Storage::disk('public')->url($path)));

    // SVGs aren't taken (they can carry scripts), and removing deletes the file.
    $this->actingAs($workspace->owner)->post("{$base}/machines/{$machine->id}/icon", ['icon' => UploadedFile::fake()->create('x.svg', 1, 'image/svg+xml')])->assertSessionHasErrors('icon');
    $this->actingAs($workspace->owner)->delete("{$base}/machines/{$machine->id}/services/app:shop/icon")->assertRedirect();
    Storage::disk('public')->assertMissing($path);
    expect($machine->fresh()->service_icons)->toBeNull();
});
