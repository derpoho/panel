<?php

use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('the services page lists every machine\'s services with where they run and their usage', function () {
    $workspace = Workspace::factory()->create();
    $online = Machine::factory()->for($workspace)->create(['name' => 'alpha']);
    $online->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'inventory' => ['services' => [
            ['key' => 'docker:abc123def456', 'name' => 'mc-survival', 'kind' => 'game_server', 'manager' => 'compose',
                'status' => 'running', 'ports' => [25565], 'confidence' => 90, 'details' => []],
        ]],
        'service_stats' => ['docker:abc123def456' => ['cpu' => 12.5, 'memUsed' => 1024]],
    ])->save();
    $offline = Machine::factory()->for($workspace)->create(['name' => 'beta']);
    $offline->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now()->subHour(),
        'inventory' => ['services' => [
            ['key' => 'systemd:nginx.service', 'name' => 'nginx', 'kind' => 'web_server', 'manager' => 'systemd',
                'status' => 'running', 'ports' => [80], 'confidence' => 90, 'details' => []],
        ]],
    ])->save();

    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/services')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/Services')
            ->has('services', 2)
            ->where('services.0.name', 'mc-survival')
            ->where('services.0.machine', ['id' => $online->id, 'name' => 'alpha'])
            ->where('services.0.stat.cpu', 12.5)
            ->where('services.1.machine.name', 'beta')
            ->where('services.1.stat', null)
            ->where('machines.0.connection', 'online')
            ->where('machines.1.connection', 'offline')
            ->has('templates')
            ->etc());
});

test('another workspace\'s services stay out of the list', function () {
    $workspace = Workspace::factory()->create();
    $other = Machine::factory()->create();
    $other->forceFill(['inventory' => ['services' => [['key' => 'docker:x', 'name' => 'secret', 'ports' => []]]]])->save();

    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/services')
        ->assertInertia(fn (Assert $page) => $page->has('services', 0)->etc());

    $this->actingAs(User::factory()->create())->get('https://'.$workspace->host().'/services')
        ->assertForbidden();
});
