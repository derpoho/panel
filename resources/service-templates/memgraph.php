<?php

return [
    'name' => 'Memgraph',
    'category' => 'Databases',
    'description' => 'In-memory graph database that speaks Cypher and Bolt.',
    'icon' => 'database',
    'kind' => 'compose',
    'port' => 7687,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Neo4j drivers work: connect to bolt://127.0.0.1:PORT with the user and password below. It only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'MEMGRAPH_USER', 'label' => 'User', 'default' => 'app'],
        ['key' => 'MEMGRAPH_PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  memgraph:
    image: memgraph/memgraph:latest
    restart: unless-stopped
    environment:
      MEMGRAPH_USER: ${MEMGRAPH_USER}
      MEMGRAPH_PASSWORD: ${MEMGRAPH_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:7687"
      - "${DOCKER_BRIDGE}:${PORT}:7687"
    volumes:
      - data:/var/lib/memgraph
volumes:
  data:
YAML,
    ],
];
