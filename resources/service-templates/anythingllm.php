<?php

return [
    'name' => 'AnythingLLM',
    'category' => 'Self-hosted',
    'description' => 'Chat with your documents using the AI model of your choice.',
    'icon' => 'bot',
    'kind' => 'compose',
    'port' => 3001,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/ping',
    'notes' => 'Open it in the browser to pick an AI provider and set a password. Without one, anyone with the address can use it.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  anythingllm:
    image: mintplexlabs/anythingllm:latest
    restart: unless-stopped
    cap_add:
      - SYS_ADMIN
    environment:
      STORAGE_DIR: /app/server/storage
    ports:
      - "127.0.0.1:${PORT}:3001"
    volumes:
      - storage:/app/server/storage
volumes:
  storage:
YAML,
    ],
];
