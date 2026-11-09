<?php

return [
    'name' => 'Dashy',
    'category' => 'Self-hosted',
    'description' => 'A customisable dashboard for your self-hosted services, with status checks.',
    'icon' => 'globe',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Edit it from the UI or in conf.yml in the user-data volume. There is no login by default, so add auth in conf.yml if it is public.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  dashy:
    image: lissy93/dashy:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:8080"
    volumes:
      - user-data:/app/user-data
volumes:
  user-data:
YAML,
    ],
];
