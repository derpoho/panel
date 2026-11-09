<?php

return [
    'name' => 'Valkey',
    'category' => 'Databases',
    'description' => 'The open source Redis fork, version 8, with persistence.',
    'icon' => 'valkey',
    'kind' => 'compose',
    'port' => 6379,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Apps you deploy on this machine connect through {bridge}, at the port shown above.',
    'env' => [
        ['key' => 'VALKEY_PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  valkey:
    image: valkey/valkey:8-alpine
    restart: unless-stopped
    command: ["valkey-server", "--requirepass", "${VALKEY_PASSWORD}", "--appendonly", "yes"]
    ports:
      - "127.0.0.1:${PORT}:6379"
      - "${DOCKER_BRIDGE}:${PORT}:6379"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
