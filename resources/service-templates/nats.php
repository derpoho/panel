<?php

return [
    'name' => 'NATS',
    'category' => 'Infrastructure',
    'description' => 'Lightweight messaging with JetStream persistence.',
    'icon' => 'nats',
    'kind' => 'compose',
    'port' => 4222,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'env' => [
        ['key' => 'NATS_USER', 'label' => 'User', 'default' => 'app'],
        ['key' => 'NATS_PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  nats:
    image: nats:2.10-alpine
    restart: unless-stopped
    command: ["--jetstream", "--store_dir", "/data", "--user", "${NATS_USER}", "--pass", "${NATS_PASSWORD}"]
    ports:
      - "127.0.0.1:${PORT}:4222"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
