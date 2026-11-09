<?php

return [
    'name' => 'Hazelcast',
    'category' => 'Databases',
    'description' => 'In-memory data grid for caching and fast shared data, on a single member.',
    'icon' => 'database',
    'kind' => 'compose',
    'port' => 5701,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Clients connect at 127.0.0.1:PORT with the cluster name below; turn off smart routing in the client. Data lives in memory, so it is gone after a restart. There is no login, so it only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'HZ_CLUSTERNAME', 'label' => 'Cluster name', 'default' => 'dev'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  hazelcast:
    image: hazelcast/hazelcast:5
    restart: unless-stopped
    environment:
      HZ_CLUSTERNAME: ${HZ_CLUSTERNAME}
    ports:
      - "127.0.0.1:${PORT}:5701"
      - "${DOCKER_BRIDGE}:${PORT}:5701"
YAML,
    ],
];
