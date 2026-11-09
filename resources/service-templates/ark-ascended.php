<?php

return [
    'name' => 'ARK: Survival Ascended',
    'category' => 'Game servers',
    'description' => 'Dedicated ARK: Survival Ascended server under Wine, with automatic update checks.',
    'icon' => 'ark',
    'kind' => 'compose',
    'port' => 7777,
    'web' => false,
    'port_editable' => false,
    'health' => null,
    'notes' => 'Uses TCP and UDP port 7777, open to the internet, so it can’t share a machine with another server on 7777 like ARK: Survival Evolved or Terraria. Needs an x86 (amd64) machine with about 16 GB of RAM and 40 GB of disk, and the first start downloads the game, so give it a while. Game settings live in ShooterGame/Saved/Config on the saved volume.',
    'env' => [
        ['key' => 'SESSION_NAME', 'label' => 'Server name', 'default' => 'ServerOS ARK Ascended'],
        ['key' => 'MAP_NAME', 'label' => 'Map', 'default' => 'TheIsland', 'hint' => 'TheIsland, TheCenter, ScorchedEarth, Ragnarok, Aberration and so on.'],
        ['key' => 'SERVER_PASSWORD', 'label' => 'Join password', 'hint' => 'Letters and numbers only. Leave empty for an open server.'],
        ['key' => 'SERVER_ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'MAX_PLAYERS', 'label' => 'Max players', 'default' => '20'],
        ['key' => 'MOD_IDS', 'label' => 'Mod IDs', 'hint' => 'CurseForge mod IDs, comma separated.'],
        ['key' => 'TZ', 'label' => 'Timezone', 'default' => 'UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  asa:
    image: acekorneya/asa_server:2_1_latest
    restart: unless-stopped
    stop_grace_period: 210s
    security_opt:
      - seccomp=unconfined
    environment:
      INSTANCE_NAME: serveros
      TZ: ${TZ}
      SESSION_NAME: ${SESSION_NAME}
      MAP_NAME: ${MAP_NAME}
      SERVER_PASSWORD: ${SERVER_PASSWORD}
      SERVER_ADMIN_PASSWORD: ${SERVER_ADMIN_PASSWORD}
      MAX_PLAYERS: ${MAX_PLAYERS}
      MOD_IDS: ${MOD_IDS}
      ASA_PORT: "7777"
      RCON_PORT: "27020"
      RCON_ENABLED: "TRUE"
      BATTLEEYE: "FALSE"
      API: "FALSE"
      UPDATE_SERVER: "TRUE"
      CHECK_FOR_UPDATE_INTERVAL: "24"
    ports:
      - "7777:7777/tcp"
      - "7777:7777/udp"
    volumes:
      - server:/home/pok/arkserver
      - saved:/home/pok/arkserver/ShooterGame/Saved
volumes:
  server:
  saved:
YAML,
    ],
];
