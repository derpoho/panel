<?php

return [
    'name' => 'Qdrant',
    'category' => 'Databases',
    'description' => 'Vector database for embeddings and semantic search. gRPC on localhost:6334.',
    'icon' => 'qdrant',
    'kind' => 'compose',
    'port' => 6333,
    'web' => true,
    'port_editable' => true,
    'health' => '/healthz',
    'notes' => 'Send the API key in the api-key header. The dashboard is at /dashboard. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'QDRANT_API_KEY', 'label' => 'API key', 'default' => '{random:32}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  qdrant:
    image: qdrant/qdrant:latest
    restart: unless-stopped
    environment:
      QDRANT__SERVICE__API_KEY: ${QDRANT_API_KEY}
    ports:
      - "127.0.0.1:${PORT}:6333"
      - "${DOCKER_BRIDGE}:${PORT}:6333"
      - "127.0.0.1:6334:6334"
    volumes:
      - data:/qdrant/storage
volumes:
  data:
YAML,
    ],
];
