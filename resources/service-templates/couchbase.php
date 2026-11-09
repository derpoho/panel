<?php

return [
    'name' => 'Couchbase',
    'category' => 'Databases',
    'description' => 'Couchbase Server Community, a JSON document database with SQL++ queries.',
    'icon' => 'couchbase',
    'kind' => 'compose',
    'port' => 8091,
    'web' => true,
    'port_editable' => false,
    'health' => null,
    'notes' => 'Open the web console to set up the cluster and your admin login. SDKs connect at couchbase://127.0.0.1 and need the fixed ports 8091 to 8096 and 11210, so those stay as they are. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  couchbase:
    image: couchbase/server:community
    restart: unless-stopped
    ulimits:
      nofile: 70000
      core: 100000000
      memlock: 100000000
    ports:
      - "127.0.0.1:${PORT}:8091"
      - "${DOCKER_BRIDGE}:${PORT}:8091"
      - "127.0.0.1:8092-8096:8092-8096"
      - "${DOCKER_BRIDGE}:8092-8096:8092-8096"
      - "127.0.0.1:11210:11210"
      - "${DOCKER_BRIDGE}:11210:11210"
    volumes:
      - data:/opt/couchbase/var
volumes:
  data:
YAML,
    ],
];
