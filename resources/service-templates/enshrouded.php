<?php

return [
    'name' => 'Enshrouded',
    'category' => 'Game servers',
    'description' => 'Dedicated Enshrouded server, run through Wine with automatic updates.',
    'icon' => 'enshrouded',
    'kind' => 'compose',
    'port' => 15637,
    'web' => false,
    'port_editable' => false,
    'health' => null,
    'notes' => 'Uses UDP port 15637, open to the internet. Players find it by this machine’s IP and that port.',
    'env' => [
        ['key' => 'SERVER_NAME', 'label' => 'Server name', 'default' => 'ServerOS Enshrouded'],
        ['key' => 'SERVER_PASSWORD', 'label' => 'Server password', 'default' => '{random:10}', 'secret' => true],
        ['key' => 'SERVER_SLOTS', 'label' => 'Slots', 'default' => '16', 'hint' => 'Up to 16.'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  enshrouded:
    image: sknnr/enshrouded-dedicated-server:latest
    restart: unless-stopped
    stop_grace_period: 1m
    environment:
      SERVER_NAME: ${SERVER_NAME}
      SERVER_PASSWORD: ${SERVER_PASSWORD}
      SERVER_SLOTS: ${SERVER_SLOTS}
      PORT: "15637"
    ports:
      - "15637:15637/udp"
    volumes:
      - savegame:/home/steam/enshrouded/savegame
volumes:
  savegame:
YAML,
    ],
];
