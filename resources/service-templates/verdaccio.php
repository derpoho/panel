<?php

return [
    'name' => 'Verdaccio',
    'category' => 'Infrastructure',
    'description' => 'Private npm registry that also caches packages from npmjs.',
    'icon' => 'verdaccio',
    'kind' => 'compose',
    'port' => 4873,
    'web' => true,
    'port_editable' => true,
    'health' => '/-/ping',
    'notes' => 'Create your account with npm adduser --registry YOUR-URL, then publish with the same --registry. With the stock config anyone can sign up and read packages, so change config.yaml in the conf volume to lock it down.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  verdaccio:
    image: verdaccio/verdaccio:6
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:4873"
      - "${DOCKER_BRIDGE}:${PORT}:4873"
    volumes:
      - storage:/verdaccio/storage
      - conf:/verdaccio/conf
volumes:
  storage:
  conf:
YAML,
    ],
];
