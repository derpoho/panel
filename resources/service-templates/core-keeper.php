<?php

return [
    'name' => 'Core Keeper',
    'category' => 'Game servers',
    'description' => 'Dedicated Core Keeper server for up to 8 friends, updated on every start.',
    'icon' => 'gamepad',
    'kind' => 'compose',
    'port' => 27017,
    'web' => false,
    'port_editable' => false,
    'health' => null,
    'notes' => 'Uses UDP port 27017, open to the internet. Players pick Join Game, then direct connect, and enter this machine’s IP, port 27017 and the password.',
    'env' => [
        ['key' => 'WORLD_NAME', 'label' => 'Server name', 'default' => 'ServerOS Core Keeper'],
        ['key' => 'PASSWORD', 'label' => 'Join password', 'default' => '{random:12}', 'secret' => true, 'hint' => 'Up to 28 characters.'],
        ['key' => 'MAX_PLAYERS', 'label' => 'Max players', 'default' => '8'],
        ['key' => 'WORLD_MODE', 'label' => 'World mode', 'default' => '0', 'hint' => '0 normal, 1 hard, 2 creative, 4 casual. Only used for a new world.'],
        ['key' => 'WORLD_SEED', 'label' => 'World seed', 'hint' => 'Leave empty for a random one.'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  core-keeper:
    image: escaping/core-keeper-dedicated:latest
    restart: unless-stopped
    stop_grace_period: 2m
    environment:
      WORLD_NAME: ${WORLD_NAME}
      PASSWORD: ${PASSWORD}
      MAX_PLAYERS: ${MAX_PLAYERS}
      WORLD_MODE: ${WORLD_MODE}
      WORLD_SEED: ${WORLD_SEED}
      SERVER_PORT: "27017"
    ports:
      - "27017:27017/udp"
    volumes:
      - files:/home/steam/core-keeper-dedicated
      - data:/home/steam/core-keeper-data
volumes:
  files:
  data:
YAML,
    ],
];
