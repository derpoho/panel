<?php

return [
    'name' => 'Flowise',
    'category' => 'Infrastructure',
    'description' => 'Build LLM agents and chat flows by dragging blocks around.',
    'icon' => 'workflow',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/v1/ping',
    'notes' => 'Open it straight after deploying to create the admin account.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  flowise:
    image: flowiseai/flowise:latest
    restart: unless-stopped
    environment:
      PORT: "3000"
      DATABASE_PATH: /root/.flowise
      SECRETKEY_PATH: /root/.flowise
      LOG_PATH: /root/.flowise/logs
      BLOB_STORAGE_PATH: /root/.flowise/storage
    ports:
      - "127.0.0.1:${PORT}:3000"
    volumes:
      - data:/root/.flowise
volumes:
  data:
YAML,
    ],
];
