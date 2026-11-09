<?php

return [
    'name' => 'linkding',
    'category' => 'Self-hosted',
    'description' => 'Minimal bookmark manager with tags and a browser extension.',
    'icon' => 'bookmark',
    'kind' => 'compose',
    'port' => 9090,
    'web' => true,
    'port_editable' => true,
    'health' => '/health',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'ADMIN_USER', 'label' => 'Admin user', 'default' => 'admin'],
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  linkding:
    image: sissbruecker/linkding:latest
    restart: unless-stopped
    environment:
      LD_SUPERUSER_NAME: ${ADMIN_USER}
      LD_SUPERUSER_PASSWORD: ${ADMIN_PASSWORD}
      LD_CSRF_TRUSTED_ORIGINS: ${APP_URL}
    ports:
      - "127.0.0.1:${PORT}:9090"
    volumes:
      - data:/etc/linkding/data
volumes:
  data:
YAML,
    ],
];
