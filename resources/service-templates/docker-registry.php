<?php

return [
    'name' => 'Docker Registry',
    'category' => 'Infrastructure',
    'description' => 'Private registry for your container images.',
    'icon' => 'docker',
    'kind' => 'compose',
    'port' => 5000,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Push from this machine with docker push 127.0.0.1:PORT/IMAGE. It has no login, so it only listens locally.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  registry:
    image: registry:2
    restart: unless-stopped
    environment:
      REGISTRY_STORAGE_DELETE_ENABLED: "true"
    ports:
      - "127.0.0.1:${PORT}:5000"
    volumes:
      - data:/var/lib/registry
volumes:
  data:
YAML,
    ],
];
