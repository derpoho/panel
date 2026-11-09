<?php

return [
    'name' => 'GoatCounter',
    'category' => 'Self-hosted',
    'description' => 'Simple, privacy-friendly web analytics without cookies.',
    'icon' => 'chart',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Open it in the browser to create your first site and account.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  goatcounter:
    image: arp242/goatcounter:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:8080"
    volumes:
      - data:/home/goatcounter/goatcounter-data
volumes:
  data:
YAML,
    ],
];
