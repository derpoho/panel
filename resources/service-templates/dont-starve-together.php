<?php

return [
    'name' => 'Don’t Starve Together',
    'category' => 'Game servers',
    'description' => 'Don’t Starve Together server with a surface world and caves, updated on every start.',
    'icon' => 'gamepad',
    'kind' => 'compose',
    'port' => 10999,
    'web' => false,
    'port_editable' => false,
    'health' => null,
    'notes' => 'Uses UDP ports 10999, 11000, 12346 and 12347, open to the internet. Needs an x86 (amd64) machine with about 1 GB of RAM. The server name, password and mods live in DoNotStarveTogether/Cluster_1 on the data volume (cluster.ini and the shard folders); restart after editing them.',
    'env' => [
        ['key' => 'DST_CLUSTER_TOKEN', 'label' => 'Cluster token', 'secret' => true, 'required' => true, 'hint' => 'In the game, open Account, then Games, Don’t Starve Together Servers, and add a new server to get one.'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  dst:
    image: jamesits/dst-server:latest
    restart: unless-stopped
    tty: true
    stdin_open: true
    stop_grace_period: 5m
    environment:
      DST_CLUSTER_TOKEN: ${DST_CLUSTER_TOKEN}
    ports:
      - "10999-11000:10999-11000/udp"
      - "12346-12347:12346-12347/udp"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
