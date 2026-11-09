<?php

return [
    'name' => 'Garnet',
    'category' => 'Databases',
    'description' => 'Fast Redis-compatible cache from Microsoft Research, with a password and an append-only log.',
    'icon' => 'database',
    'kind' => 'compose',
    'port' => 6379,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Works with any Redis client, using the password below. Apps you deploy on this machine connect through {bridge}, at the port shown above.',
    'env' => [
        ['key' => 'GARNET_PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'GARNET_MEMORY', 'label' => 'Memory', 'default' => '512m'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  garnet:
    image: ghcr.io/microsoft/garnet:latest
    restart: unless-stopped
    command:
      - --port=6379
      - --memory=${GARNET_MEMORY}
      - --auth=Password
      - --password=${GARNET_PASSWORD}
      - --checkpointdir=/data
      - --aof
      - --recover
    ulimits:
      memlock: -1
    ports:
      - "127.0.0.1:${PORT}:6379"
      - "${DOCKER_BRIDGE}:${PORT}:6379"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
