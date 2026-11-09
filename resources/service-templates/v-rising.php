<?php

return [
    'name' => 'V Rising',
    'category' => 'Game servers',
    'description' => 'Dedicated V Rising server that installs and updates itself on start.',
    'icon' => 'gamepad',
    'kind' => 'compose',
    'port' => 9876,
    'web' => false,
    'port_editable' => false,
    'health' => null,
    'notes' => 'Uses UDP ports 9876 and 9877, open to the internet. Needs an x86 (amd64) machine with about 4 GB of RAM, and the first start downloads the game, so give it up to 10 minutes. Passwords, descriptions and game rules live in Settings/ServerHostSettings.json and ServerGameSettings.json on the data volume.',
    'env' => [
        ['key' => 'SERVERNAME', 'label' => 'Server name', 'default' => 'ServerOS V Rising'],
        ['key' => 'WORLDNAME', 'label' => 'World name', 'default' => 'world1'],
        ['key' => 'TZ', 'label' => 'Timezone', 'default' => 'UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  vrising:
    image: trueosiris/vrising:latest
    restart: unless-stopped
    stop_grace_period: 2m
    environment:
      SERVERNAME: ${SERVERNAME}
      WORLDNAME: ${WORLDNAME}
      TZ: ${TZ}
    ports:
      - "9876-9877:9876-9877/udp"
    volumes:
      - server:/mnt/vrising/server
      - data:/mnt/vrising/persistentdata
volumes:
  server:
  data:
YAML,
    ],
];
