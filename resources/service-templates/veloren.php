<?php

return [
    'name' => 'Veloren',
    'category' => 'Game servers',
    'description' => 'Server for Veloren, the open source multiplayer voxel RPG.',
    'icon' => 'gamepad',
    'kind' => 'compose',
    'port' => 14004,
    'web' => false,
    'port_editable' => false,
    'health' => null,
    'notes' => 'Uses TCP port 14004, open to the internet. Players add this machine’s IP as a server in the game. To make yourself an admin, open the service console and run admin add YOUR_NAME admin. Uses the weekly build, so players need a matching game version.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  veloren:
    image: registry.gitlab.com/veloren/veloren/server-cli:weekly
    restart: unless-stopped
    tty: true
    stdin_open: true
    stop_grace_period: 2m
    environment:
      RUST_LOG: info
    ports:
      - "14004:14004/tcp"
    volumes:
      - data:/opt/userdata
volumes:
  data:
YAML,
    ],
];
