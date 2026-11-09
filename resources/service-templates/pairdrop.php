<?php

return [
    'name' => 'PairDrop',
    'category' => 'Self-hosted',
    'description' => 'AirDrop in the browser, send files between your devices.',
    'icon' => 'globe',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'No accounts. Add a domain with HTTPS so browsers allow the device to device connection.',
    'env' => [
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  pairdrop:
    image: lscr.io/linuxserver/pairdrop:latest
    restart: unless-stopped
    environment:
      PUID: "1000"
      PGID: "1000"
      TZ: ${TIMEZONE}
    ports:
      - "127.0.0.1:${PORT}:3000"
YAML,
    ],
];
