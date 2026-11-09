<?php

return [
    'name' => 'Homepage',
    'category' => 'Self-hosted',
    'description' => 'A start page for your services, with widgets for the apps you run.',
    'icon' => 'homepage',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'It is set up with YAML files in the config volume (services.yaml, widgets.yaml and so on). There is no login, so keep it behind access rules if it is public.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  homepage:
    image: ghcr.io/gethomepage/homepage:latest
    restart: unless-stopped
    environment:
      HOMEPAGE_ALLOWED_HOSTS: "*"
    ports:
      - "127.0.0.1:${PORT}:3000"
    volumes:
      - config:/app/config
volumes:
  config:
YAML,
    ],
];
