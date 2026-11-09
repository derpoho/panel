<?php

return [
    'name' => 'Satisfactory',
    'category' => 'Game servers',
    'description' => 'Dedicated Satisfactory server with automatic updates.',
    'icon' => 'satisfactory',
    'kind' => 'compose',
    'port' => 7777,
    'web' => false,
    'port_editable' => false,
    'health' => null,
    'notes' => 'Uses port 7777 (UDP and TCP) and 8888 (TCP), open to the internet. Claim the server in-game to set the admin password. Wants 8 GB of memory or more.',
    'env' => [
        ['key' => 'MAX_PLAYERS', 'label' => 'Max players', 'default' => '4'],
        ['key' => 'MEMORY_LIMIT', 'label' => 'Memory limit', 'default' => '8G'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  satisfactory:
    image: wolveix/satisfactory-server:latest
    restart: unless-stopped
    stop_grace_period: 1m
    environment:
      MAXPLAYERS: ${MAX_PLAYERS}
      PGID: "1000"
      PUID: "1000"
      STEAMBETA: "false"
    deploy:
      resources:
        limits:
          memory: ${MEMORY_LIMIT}
    ports:
      - "7777:7777/udp"
      - "7777:7777/tcp"
      - "8888:8888/tcp"
    volumes:
      - config:/config
volumes:
  config:
YAML,
    ],
];
