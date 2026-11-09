<?php

return [
    'name' => 'KurrentDB (EventStoreDB)',
    'category' => 'Databases',
    'description' => 'Event store for event sourcing, formerly EventStoreDB, on a single node.',
    'icon' => 'eventstore',
    'kind' => 'compose',
    'port' => 2113,
    'web' => false,
    'port_editable' => true,
    'health' => '/health/live',
    'notes' => 'Connect with kurrentdb://127.0.0.1:PORT?tls=false. The web UI is on the same port. It runs without TLS or a login, so it only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  kurrentdb:
    image: kurrentplatform/kurrentdb:latest
    restart: unless-stopped
    environment:
      KURRENTDB_INSECURE: "true"
      KURRENTDB_RUN_PROJECTIONS: All
      KURRENTDB_START_STANDARD_PROJECTIONS: "true"
      KURRENTDB_ENABLE_ATOM_PUB_OVER_HTTP: "true"
    ports:
      - "127.0.0.1:${PORT}:2113"
      - "${DOCKER_BRIDGE}:${PORT}:2113"
    volumes:
      - data:/var/lib/kurrentdb
      - logs:/var/log/kurrentdb
volumes:
  data:
  logs:
YAML,
    ],
];
