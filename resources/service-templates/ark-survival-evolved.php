<?php

return [
    'name' => 'ARK: Survival Evolved',
    'category' => 'Game servers',
    'description' => 'Dedicated ARK: Survival Evolved server, managed by arkmanager and updated on every start.',
    'icon' => 'ark',
    'kind' => 'compose',
    'port' => 7777,
    'web' => false,
    'port_editable' => false,
    'health' => null,
    'notes' => 'Uses UDP ports 7777, 7778 and 27015, open to the internet, so it can’t share a machine with another server on 27015 like Counter-Strike 2. Needs an x86 (amd64) machine with about 25 GB of disk and 8 GB of RAM, and the first start downloads the game, so give it a while. This is ARK: Survival Evolved, not Ascended.',
    'env' => [
        ['key' => 'SESSION_NAME', 'label' => 'Server name', 'default' => 'ServerOS ARK'],
        ['key' => 'SERVER_MAP', 'label' => 'Map', 'default' => 'TheIsland', 'hint' => 'TheIsland, ScorchedEarth_P, Aberration_P, Extinction, Ragnarok, TheCenter, Valguero_P and so on.'],
        ['key' => 'SERVER_PASSWORD', 'label' => 'Join password', 'hint' => 'Leave empty for an open server.'],
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'MAX_PLAYERS', 'label' => 'Max players', 'default' => '20'],
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  ark:
    image: hermsi/ark-server:latest
    restart: unless-stopped
    stop_grace_period: 5m
    environment:
      SESSION_NAME: ${SESSION_NAME}
      SERVER_MAP: ${SERVER_MAP}
      SERVER_PASSWORD: ${SERVER_PASSWORD}
      ADMIN_PASSWORD: ${ADMIN_PASSWORD}
      MAX_PLAYERS: ${MAX_PLAYERS}
      TZ: ${TIMEZONE}
      UPDATE_ON_START: "true"
      PRE_UPDATE_BACKUP: "true"
      WARN_ON_STOP: "true"
    ports:
      - "7777:7777/udp"
      - "7778:7778/udp"
      - "27015:27015/udp"
    volumes:
      - data:/app
volumes:
  data:
YAML,
    ],
];
