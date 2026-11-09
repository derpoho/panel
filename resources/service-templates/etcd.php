<?php

return [
    'name' => 'etcd',
    'category' => 'Databases',
    'description' => 'Consistent key-value store for config and coordination, on a single node.',
    'icon' => 'etcd',
    'kind' => 'compose',
    'port' => 2379,
    'web' => false,
    'port_editable' => true,
    'health' => '/health',
    'notes' => 'Use etcdctl --endpoints=http://127.0.0.1:PORT. There is no login, so it only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  etcd:
    image: quay.io/coreos/etcd:v3.6.16
    restart: unless-stopped
    command: etcd --name etcd --data-dir /etcd-data --listen-client-urls http://0.0.0.0:2379 --advertise-client-urls http://127.0.0.1:2379
    ports:
      - "127.0.0.1:${PORT}:2379"
      - "${DOCKER_BRIDGE}:${PORT}:2379"
    volumes:
      - data:/etcd-data
volumes:
  data:
YAML,
    ],
];
