<?php

return [
    'name' => 'Apache Cassandra',
    'category' => 'Databases',
    'description' => 'Wide-column database, Cassandra 5 on a single node.',
    'icon' => 'apachecassandra',
    'kind' => 'compose',
    'port' => 9042,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Connect with cqlsh or a CQL driver at 127.0.0.1 on the port you chose. There is no login, so it only listens locally. Give it a minute or two to start. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'MAX_HEAP_SIZE', 'label' => 'Max heap size', 'default' => '1G'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  cassandra:
    image: cassandra:5
    restart: unless-stopped
    environment:
      MAX_HEAP_SIZE: ${MAX_HEAP_SIZE}
      HEAP_NEWSIZE: 256M
      CASSANDRA_CLUSTER_NAME: serveros
    ports:
      - "127.0.0.1:${PORT}:9042"
      - "${DOCKER_BRIDGE}:${PORT}:9042"
    volumes:
      - data:/var/lib/cassandra
volumes:
  data:
YAML,
    ],
];
