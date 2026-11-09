<?php

return [
    'name' => 'MicroBin',
    'category' => 'Self-hosted',
    'description' => 'Tiny pastebin and file sharing app with expiring links.',
    'icon' => 'scroll',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Anyone with the address can paste. The admin page is at /admin.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'ADMIN_USER', 'label' => 'Admin user', 'default' => 'admin'],
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  microbin:
    image: danielszabo99/microbin:latest
    restart: unless-stopped
    environment:
      MICROBIN_PORT: "8080"
      MICROBIN_PUBLIC_PATH: ${APP_URL}
      MICROBIN_ADMIN_USERNAME: ${ADMIN_USER}
      MICROBIN_ADMIN_PASSWORD: ${ADMIN_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:8080"
    volumes:
      - data:/app/microbin_data
volumes:
  data:
YAML,
    ],
];
