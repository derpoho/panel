<?php

use App\Models\Machine;
use App\Support\Deploys\AppReleases;
use App\Support\Deploys\ContainerNames;
use App\Support\Deploys\ServiceLabels;

function pteroContainer(string $image, string $kind = 'app', string $name = '10279aa4-91dd-4187-a5d6-3231719a725d'): array
{
    return ['key' => 'docker:'.substr(md5($name.$image), 0, 12), 'name' => $name, 'kind' => $kind, 'details' => ['image' => $image, 'mounts' => '/var/lib/serveros/volumes/x:/home/container']];
}

test('images say what a container runs', function () {
    expect(ContainerNames::fromImage('5140/discord-egg:latest'))->toBe('Discord egg')
        ->and(ContainerNames::fromImage('ghcr.io/pterodactyl/yolks:java_17'))->toBe('Java 17')
        ->and(ContainerNames::fromImage('ghcr.io/pterodactyl/yolks:java_17', 'game_server'))->toBe('Minecraft')
        ->and(ContainerNames::fromImage('ghcr.io/ptero-eggs/yolks:nodejs_22'))->toBe('Node.js 22')
        ->and(ContainerNames::fromImage('mysql:8'))->toBe('MySQL')
        ->and(ContainerNames::fromImage('postgres:16@sha256:abc123'))->toBe('PostgreSQL')
        ->and(ContainerNames::fromImage('sha256:abcdef'))->toBeNull()
        ->and(ContainerNames::fromImage(''))->toBeNull();
});

test('containers named by a UUID go by what they run, with the UUID’s first block', function () {
    expect(ContainerNames::found(pteroContainer('5140/discord-egg:latest')))->toBe('Discord egg · 10279aa4')
        ->and(ContainerNames::found(pteroContainer('ghcr.io/pterodactyl/yolks:java_17', 'game_server', 'df37c8dd-ac9e-4ff9-bdec-24e559e4b81d')))->toBe('Minecraft · df37c8dd');

    // Set up by Pterodactyl but named otherwise: still named from the image.
    expect(ContainerNames::found(['key' => 'docker:x', 'name' => 'mc', 'details' => ['image' => 'itzg/minecraft-server', 'mounts' => '/run/wings/machine-id/x:/etc/machine-id']]))->toBe('Minecraft server');

    // Containers with a name of their own, and anything not a container, keep it.
    expect(ContainerNames::found(['key' => 'docker:y', 'name' => 'serveros-shop-db-1', 'details' => ['image' => 'postgres:16']]))->toBeNull()
        ->and(ContainerNames::found(['key' => 'systemd:nginx.service', 'name' => '10279aa4-91dd-4187-a5d6-3231719a725d', 'details' => []]))->toBeNull();
});

test('the panel shows the found name unless someone named it', function () {
    $machine = Machine::factory()->create();
    $bot = pteroContainer('5140/discord-egg:latest');
    $machine->forceFill(['inventory' => ['services' => [$bot]]])->save();

    expect(AppReleases::visible($machine)[0]['found'])->toBe('Discord egg · 10279aa4')
        ->and(ServiceLabels::display($machine, $bot))->toBe('Discord egg · 10279aa4');

    ServiceLabels::set($machine, $bot, 'Discord bot');
    expect(ServiceLabels::display($machine->fresh(), $bot))->toBe('Discord bot');
});
