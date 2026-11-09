<?php

return [
    'name' => 'Chroma',
    'category' => 'Databases',
    'description' => 'Open source vector database for embeddings and AI search.',
    'icon' => 'database',
    'kind' => 'compose',
    'port' => 8000,
    'web' => false,
    'port_editable' => true,
    'health' => '/api/v2/heartbeat',
    'notes' => 'Connect a Chroma client at http://127.0.0.1:PORT. There is no login, so it only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  chroma:
    image: chromadb/chroma:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:8000"
      - "${DOCKER_BRIDGE}:${PORT}:8000"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
