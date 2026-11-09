<?php

return [
    'name' => 'Neo4j',
    'category' => 'Databases',
    'description' => 'Graph database with the Neo4j Browser. Bolt on localhost:7687.',
    'icon' => 'neo4j',
    'kind' => 'compose',
    'port' => 7474,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Log in to the browser as neo4j with the password below. Drivers connect to bolt://127.0.0.1:7687. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'NEO4J_PASSWORD', 'label' => 'Password for neo4j', 'default' => '{random:24}', 'secret' => true, 'hint' => 'At least 8 characters.'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  neo4j:
    image: neo4j:5
    restart: unless-stopped
    environment:
      NEO4J_AUTH: neo4j/${NEO4J_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:7474"
      - "${DOCKER_BRIDGE}:${PORT}:7474"
      - "127.0.0.1:7687:7687"
    volumes:
      - data:/data
      - logs:/logs
volumes:
  data:
  logs:
YAML,
    ],
];
