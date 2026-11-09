<?php

return [
    'name' => 'Team Fortress 2',
    'category' => 'Game servers',
    'description' => 'Dedicated Team Fortress 2 server that updates itself on every start.',
    'icon' => 'valve',
    'kind' => 'compose',
    'port' => 27015,
    'web' => false,
    'port_editable' => false,
    'health' => null,
    'notes' => 'Uses TCP and UDP port 27015 and UDP port 27020 for SourceTV, open to the internet, so it can’t share a machine with another Source server on 27015 like Counter-Strike 2. Needs an x86 (amd64) machine with about 20 GB of disk, and the first start downloads the game, so give it a while. Without a Steam game server token it stays off the server browser. More settings live in tf/cfg/server.cfg on the data volume.',
    'env' => [
        ['key' => 'SRCDS_HOSTNAME', 'label' => 'Server name', 'default' => 'ServerOS TF2', 'hint' => 'Only used on the first start.'],
        ['key' => 'SRCDS_TOKEN', 'label' => 'Steam game server token', 'secret' => true, 'hint' => 'From steamcommunity.com/dev/managegameservers, with app ID 440.'],
        ['key' => 'SRCDS_PW', 'label' => 'Join password', 'hint' => 'Leave empty for an open server.'],
        ['key' => 'SRCDS_RCONPW', 'label' => 'RCON password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'SRCDS_MAXPLAYERS', 'label' => 'Max players', 'default' => '24'],
        ['key' => 'SRCDS_STARTMAP', 'label' => 'Start map', 'default' => 'ctf_2fort'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  tf2:
    image: cm2network/tf2:latest
    restart: unless-stopped
    stop_grace_period: 30s
    environment:
      SRCDS_HOSTNAME: ${SRCDS_HOSTNAME}
      SRCDS_TOKEN: ${SRCDS_TOKEN}
      SRCDS_PW: ${SRCDS_PW}
      SRCDS_RCONPW: ${SRCDS_RCONPW}
      SRCDS_MAXPLAYERS: ${SRCDS_MAXPLAYERS}
      SRCDS_STARTMAP: ${SRCDS_STARTMAP}
      SRCDS_PORT: "27015"
      SRCDS_TV_PORT: "27020"
    ports:
      - "27015:27015/tcp"
      - "27015:27015/udp"
      - "27020:27020/udp"
    volumes:
      - data:/home/steam/tf-dedicated
volumes:
  data:
YAML,
    ],
];
