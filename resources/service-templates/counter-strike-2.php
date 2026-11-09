<?php

return [
    'name' => 'Counter-Strike 2',
    'category' => 'Game servers',
    'description' => 'Dedicated Counter-Strike 2 server that updates itself on every start.',
    'icon' => 'counterstrike',
    'kind' => 'compose',
    'port' => 27015,
    'web' => false,
    'port_editable' => false,
    'health' => null,
    'notes' => 'Uses TCP and UDP port 27015, open to the internet. Needs an x86 (amd64) machine with about 60 GB of disk and 2 GB of RAM, and the first start downloads the game, so give it a while. Without a Steam game server token it only takes direct connections and stays off the server browser.',
    'env' => [
        ['key' => 'CS2_SERVERNAME', 'label' => 'Server name', 'default' => 'ServerOS CS2', 'hint' => 'Write a / as \\/.'],
        ['key' => 'SRCDS_TOKEN', 'label' => 'Steam game server token', 'secret' => true, 'hint' => 'From steamcommunity.com/dev/managegameservers, with app ID 730. Leave empty for a private server.'],
        ['key' => 'CS2_PW', 'label' => 'Join password', 'hint' => 'Leave empty for an open server.'],
        ['key' => 'CS2_RCONPW', 'label' => 'RCON password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'CS2_MAXPLAYERS', 'label' => 'Max players', 'default' => '10'],
        ['key' => 'CS2_GAMEALIAS', 'label' => 'Game mode', 'default' => 'competitive', 'hint' => 'competitive, casual, deathmatch, wingman and so on.'],
        ['key' => 'CS2_STARTMAP', 'label' => 'Start map', 'default' => 'de_inferno'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  cs2:
    image: joedwards32/cs2:latest
    restart: unless-stopped
    stop_grace_period: 30s
    environment:
      SRCDS_TOKEN: ${SRCDS_TOKEN}
      CS2_SERVERNAME: ${CS2_SERVERNAME}
      CS2_PORT: "27015"
      CS2_PW: ${CS2_PW}
      CS2_RCONPW: ${CS2_RCONPW}
      CS2_MAXPLAYERS: ${CS2_MAXPLAYERS}
      CS2_GAMEALIAS: ${CS2_GAMEALIAS}
      CS2_STARTMAP: ${CS2_STARTMAP}
      CS2_LAN: "0"
      CS2_SERVER_HIBERNATE: "0"
      TV_ENABLE: "0"
    ports:
      - "27015:27015/tcp"
      - "27015:27015/udp"
    volumes:
      - data:/home/steam/cs2-dedicated
volumes:
  data:
YAML,
    ],
];
