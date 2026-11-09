<?php

return [
    'name' => 'Manticore Search',
    'category' => 'Infrastructure',
    'description' => 'Fast full-text search database you talk to over SQL or JSON.',
    'icon' => 'search',
    'kind' => 'compose',
    'port' => 9308,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'The main port is the HTTP JSON API. MySQL clients connect on the SQL port. Both listen on {bridge} for apps you deploy on this machine. It has no login, so it only listens locally and on the Docker bridge.',
    'env' => [
        ['key' => 'SQL_PORT', 'label' => 'SQL port', 'default' => '9306'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  manticore:
    image: manticoresearch/manticore:latest
    restart: unless-stopped
    environment:
      EXTRA: "1"
    ulimits:
      nproc: 65535
      nofile:
        soft: 65535
        hard: 65535
      memlock:
        soft: -1
        hard: -1
    ports:
      - "127.0.0.1:${PORT}:9308"
      - "${DOCKER_BRIDGE}:${PORT}:9308"
      - "127.0.0.1:${SQL_PORT}:9306"
      - "${DOCKER_BRIDGE}:${SQL_PORT}:9306"
    volumes:
      - data:/var/lib/manticore
volumes:
  data:
YAML,
    ],
];
