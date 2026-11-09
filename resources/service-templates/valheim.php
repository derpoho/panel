<?php

return [
    'name' => 'Valheim',
    'category' => 'Game servers',
    'description' => 'Dedicated Valheim server with automatic updates and backups.',
    'icon' => 'valheim',
    'kind' => 'compose',
    'port' => 2456,
    'web' => false,
    'port_editable' => false,
    'health' => null,
    'notes' => 'Uses UDP ports 2456 and 2457, open to the internet.',
    'env' => [
        ['key' => 'SERVER_NAME', 'label' => 'Server name', 'default' => 'ServerOS Valheim'],
        ['key' => 'WORLD_NAME', 'label' => 'World name', 'default' => 'Dedicated'],
        ['key' => 'SERVER_PASS', 'label' => 'Server password', 'default' => '{random:10}', 'secret' => true, 'hint' => 'At least 5 characters, and not part of the server name.'],
        ['key' => 'SERVER_PUBLIC', 'label' => 'List publicly', 'default' => 'false'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  valheim:
    image: lloesche/valheim-server:latest
    restart: unless-stopped
    cap_add:
      - sys_nice
    stop_grace_period: 2m
    environment:
      SERVER_NAME: ${SERVER_NAME}
      WORLD_NAME: ${WORLD_NAME}
      SERVER_PASS: ${SERVER_PASS}
      SERVER_PUBLIC: ${SERVER_PUBLIC}
    ports:
      - "2456-2457:2456-2457/udp"
    volumes:
      - config:/config
      - data:/opt/valheim
volumes:
  config:
  data:
YAML,
    ],
];
