<?php

return [
    'name' => 'JanusGraph',
    'category' => 'Databases',
    'description' => 'Graph database you query with Gremlin, stored on disk with BerkeleyDB.',
    'icon' => 'database',
    'kind' => 'compose',
    'port' => 8182,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Connect a Gremlin client at ws://127.0.0.1:PORT/gremlin. There is no login, so it only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  janusgraph:
    image: janusgraph/janusgraph:latest
    restart: unless-stopped
    environment:
      JANUS_PROPS_TEMPLATE: berkeleyje-lucene
    ports:
      - "127.0.0.1:${PORT}:8182"
      - "${DOCKER_BRIDGE}:${PORT}:8182"
    volumes:
      - data:/var/lib/janusgraph
volumes:
  data:
YAML,
    ],
];
