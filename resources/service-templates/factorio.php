<?php

return [
    'name' => 'Factorio',
    'category' => 'Game servers',
    'description' => 'Headless Factorio server. RCON on localhost:27015.',
    'icon' => 'factorio',
    'kind' => 'compose',
    'port' => 34197,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'UDP, open to the internet. The RCON password is in /factorio/config/rconpw inside the volume.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  factorio:
    image: factoriotools/factorio:stable
    restart: unless-stopped
    ports:
      - "${PORT}:34197/udp"
      - "127.0.0.1:27015:27015/tcp"
    volumes:
      - data:/factorio
volumes:
  data:
YAML,
    ],
];
