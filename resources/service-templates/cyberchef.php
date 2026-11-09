<?php

return [
    'name' => 'CyberChef',
    'category' => 'Infrastructure',
    'description' => 'Encode, decode, decrypt and pick apart data in the browser, from GCHQ.',
    'icon' => 'lock',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Everything runs in the browser and nothing is stored, so there is no login.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  cyberchef:
    image: ghcr.io/gchq/cyberchef:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:80"
YAML,
    ],
];
