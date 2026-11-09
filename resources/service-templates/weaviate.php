<?php

return [
    'name' => 'Weaviate',
    'category' => 'Databases',
    'description' => 'Vector database with hybrid search, locked with an API key.',
    'icon' => 'database',
    'kind' => 'compose',
    'port' => 8080,
    'web' => false,
    'port_editable' => true,
    'health' => '/v1/.well-known/ready',
    'notes' => 'Connect a Weaviate client at http://127.0.0.1:PORT with the API key, and gRPC on the gRPC port. It only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'WEAVIATE_API_KEY', 'label' => 'API key', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'GRPC_PORT', 'label' => 'gRPC port', 'default' => '50051'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  weaviate:
    image: semitechnologies/weaviate:latest
    restart: unless-stopped
    command: ["--host", "0.0.0.0", "--port", "8080", "--scheme", "http"]
    environment:
      PERSISTENCE_DATA_PATH: /var/lib/weaviate
      QUERY_DEFAULTS_LIMIT: 25
      DEFAULT_VECTORIZER_MODULE: none
      CLUSTER_HOSTNAME: node1
      AUTHENTICATION_ANONYMOUS_ACCESS_ENABLED: "false"
      AUTHENTICATION_APIKEY_ENABLED: "true"
      AUTHENTICATION_APIKEY_ALLOWED_KEYS: ${WEAVIATE_API_KEY}
      AUTHENTICATION_APIKEY_USERS: admin
    ports:
      - "127.0.0.1:${PORT}:8080"
      - "${DOCKER_BRIDGE}:${PORT}:8080"
      - "127.0.0.1:${GRPC_PORT}:50051"
      - "${DOCKER_BRIDGE}:${GRPC_PORT}:50051"
    volumes:
      - data:/var/lib/weaviate
volumes:
  data:
YAML,
    ],
];
