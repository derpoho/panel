<?php

return [
    'name' => 'Apache Ignite',
    'category' => 'Databases',
    'description' => 'In-memory database and cache with SQL, on a single node.',
    'icon' => 'database',
    'kind' => 'compose',
    'port' => 10800,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Thin clients and JDBC connect at 127.0.0.1:PORT. Data lives in memory, so it is gone after a restart. There is no login, so it only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'JVM_OPTS', 'label' => 'JVM options', 'default' => '-Xms512m -Xmx1g'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  ignite:
    image: apacheignite/ignite:2.18.0
    restart: unless-stopped
    environment:
      JVM_OPTS: ${JVM_OPTS}
    ports:
      - "127.0.0.1:${PORT}:10800"
      - "${DOCKER_BRIDGE}:${PORT}:10800"
YAML,
    ],
];
