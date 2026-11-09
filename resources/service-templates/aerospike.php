<?php

return [
    'name' => 'Aerospike',
    'category' => 'Databases',
    'description' => 'Aerospike Community, a fast key-value and document store kept on disk.',
    'icon' => 'database',
    'kind' => 'compose',
    'port' => 3000,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Clients connect at 127.0.0.1:PORT and use the namespace below. There is no login in the community edition, so it only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'NAMESPACE', 'label' => 'Namespace', 'default' => 'app'],
        ['key' => 'STORAGE_GB', 'label' => 'Storage size (GB)', 'default' => '4'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  aerospike:
    image: aerospike/aerospike-server:latest
    restart: unless-stopped
    environment:
      NAMESPACE: ${NAMESPACE}
      STORAGE_GB: ${STORAGE_GB}
    ports:
      - "127.0.0.1:${PORT}:3000"
      - "${DOCKER_BRIDGE}:${PORT}:3000"
    volumes:
      - data:/opt/aerospike/data
volumes:
  data:
YAML,
    ],
];
