<?php

return [
    'name' => 'OpenTTD',
    'category' => 'Game servers',
    'description' => 'Dedicated OpenTTD server for multiplayer transport tycoon games.',
    'icon' => 'gamepad',
    'kind' => 'compose',
    'port' => 3979,
    'web' => false,
    'port_editable' => false,
    'health' => null,
    'notes' => 'Uses TCP and UDP port 3979, open to the internet. Players pick Multiplayer, then Add Server, and enter this machine’s IP. The server name, password and game settings live in openttd.cfg on the data volume; it picks up the save from the last shutdown when there is one.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  openttd:
    image: bateau/openttd:latest
    restart: unless-stopped
    environment:
      loadgame: exit
    ports:
      - "3979:3979/tcp"
      - "3979:3979/udp"
    volumes:
      - data:/home/openttd/.openttd
volumes:
  data:
YAML,
    ],
];
