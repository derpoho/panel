<?php

return [
    'name' => 'Project Zomboid',
    'category' => 'Game servers',
    'description' => 'Dedicated Project Zomboid server with automatic updates.',
    'icon' => 'zomboid',
    'kind' => 'compose',
    'port' => 16261,
    'web' => false,
    'port_editable' => false,
    'health' => null,
    'notes' => 'Uses UDP ports 16261 and 16262, open to the internet. The first boot downloads the game, which takes a while.',
    'env' => [
        ['key' => 'SERVER_NAME', 'label' => 'Server name', 'default' => 'ServerOS', 'hint' => 'Letters and numbers only.'],
        ['key' => 'SERVER_PASSWORD', 'label' => 'Server password', 'default' => '{random:10}', 'secret' => true],
        ['key' => 'ADMIN_USERNAME', 'label' => 'Admin user', 'default' => 'admin'],
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:16}', 'secret' => true],
        ['key' => 'MAX_PLAYERS', 'label' => 'Max players', 'default' => '16'],
        ['key' => 'MAX_RAM', 'label' => 'Memory', 'default' => '4096m'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  zomboid:
    image: renegademaster/zomboid-dedicated-server:latest
    restart: unless-stopped
    stop_grace_period: 1m
    environment:
      SERVER_NAME: ${SERVER_NAME}
      SERVER_PASSWORD: ${SERVER_PASSWORD}
      ADMIN_USERNAME: ${ADMIN_USERNAME}
      ADMIN_PASSWORD: ${ADMIN_PASSWORD}
      MAX_PLAYERS: ${MAX_PLAYERS}
      MAX_RAM: ${MAX_RAM}
    ports:
      - "16261:16261/udp"
      - "16262:16262/udp"
    volumes:
      - server:/home/steam/ZomboidDedicatedServer
      - data:/home/steam/Zomboid
volumes:
  server:
  data:
YAML,
    ],
];
