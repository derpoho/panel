<?php

return [
    'name' => 'RustDesk Server',
    'category' => 'Self-hosted',
    'description' => 'Your own ID and relay server for RustDesk remote desktop.',
    'icon' => 'rustdesk',
    'kind' => 'compose',
    'port' => 21116,
    'web' => false,
    'port_editable' => false,
    'health' => null,
    'notes' => 'Uses TCP ports 21115 to 21119 and UDP 21116, open to the internet. In each RustDesk client set the ID server and relay server to this machine\'s address, and paste the key from id_ed25519.pub in the data volume (also printed in the hbbs logs).',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  hbbs:
    image: rustdesk/rustdesk-server:latest
    restart: unless-stopped
    command: hbbs
    depends_on: [hbbr]
    ports:
      - "21115:21115"
      - "21116:21116"
      - "21116:21116/udp"
      - "21118:21118"
    volumes:
      - data:/root
  hbbr:
    image: rustdesk/rustdesk-server:latest
    restart: unless-stopped
    command: hbbr
    ports:
      - "21117:21117"
      - "21119:21119"
    volumes:
      - data:/root
volumes:
  data:
YAML,
    ],
];
