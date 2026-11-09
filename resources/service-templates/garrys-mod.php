<?php

return [
    'name' => 'Garry’s Mod',
    'category' => 'Game servers',
    'description' => 'Garry’s Mod server with Counter-Strike: Source content, updated on every start.',
    'icon' => 'gamepad',
    'kind' => 'compose',
    'port' => 27015,
    'web' => false,
    'port_editable' => false,
    'health' => null,
    'notes' => 'Uses TCP and UDP port 27015 and UDP port 27005, open to the internet, so it can’t share a machine with another Source server on 27015 like Counter-Strike 2. Needs an x86 (amd64) machine. Addons and gamemodes go in the addons and gamemodes folders of the data volume.',
    'env' => [
        ['key' => 'HOSTNAME', 'label' => 'Server name', 'default' => 'ServerOS Garry’s Mod'],
        ['key' => 'GAMEMODE', 'label' => 'Gamemode', 'default' => 'sandbox', 'hint' => 'sandbox, terrortown, darkrp and so on, if installed.'],
        ['key' => 'MAP', 'label' => 'Map', 'default' => 'gm_construct'],
        ['key' => 'MAXPLAYERS', 'label' => 'Max players', 'default' => '16'],
        ['key' => 'GSLT', 'label' => 'Steam game server token', 'secret' => true, 'hint' => 'From steamcommunity.com/dev/managegameservers, with app ID 4000. Leave empty to skip the server browser.'],
        ['key' => 'ARGS', 'label' => 'Extra launch options', 'hint' => 'Like +host_workshop_collection 382793424.'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  gmod:
    image: ceifa/garrysmod:latest
    restart: unless-stopped
    tty: true
    stdin_open: true
    environment:
      PRODUCTION: "1"
      HOSTNAME: ${HOSTNAME}
      GAMEMODE: ${GAMEMODE}
      MAP: ${MAP}
      MAXPLAYERS: ${MAXPLAYERS}
      GSLT: ${GSLT}
      ARGS: ${ARGS}
    ports:
      - "27015:27015/tcp"
      - "27015:27015/udp"
      - "27005:27005/udp"
    volumes:
      - data:/home/gmod/server/garrysmod
volumes:
  data:
YAML,
    ],
];
