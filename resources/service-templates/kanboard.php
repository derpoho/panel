<?php

return [
    'name' => 'Kanboard',
    'category' => 'Self-hosted',
    'description' => 'A simple, no-fuss kanban board for getting things done.',
    'icon' => 'table',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'The first login is admin / admin. Change it straight away.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  kanboard:
    image: kanboard/kanboard:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - data:/var/www/app/data
      - plugins:/var/www/app/plugins
volumes:
  data:
  plugins:
YAML,
    ],
];
