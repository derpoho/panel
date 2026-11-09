<?php

return [
    'name' => 'ScyllaDB',
    'category' => 'Databases',
    'description' => 'Cassandra-compatible database written in C++, on a single node.',
    'icon' => 'scylladb',
    'kind' => 'compose',
    'port' => 9042,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Connect with cqlsh or any CQL driver at 127.0.0.1 on the port you chose. There is no login, so it only listens locally. It runs in developer mode on one CPU core. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'SCYLLA_MEMORY', 'label' => 'Memory', 'default' => '1G'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  scylla:
    image: scylladb/scylla:latest
    restart: unless-stopped
    command: --smp 1 --memory ${SCYLLA_MEMORY} --overprovisioned 1 --developer-mode 1
    ports:
      - "127.0.0.1:${PORT}:9042"
      - "${DOCKER_BRIDGE}:${PORT}:9042"
    volumes:
      - data:/var/lib/scylla
volumes:
  data:
YAML,
    ],
];
