<?php

return [
    'name' => 'ArchiveBox',
    'category' => 'Self-hosted',
    'description' => 'Saves copies of web pages so they never go missing.',
    'icon' => 'box',
    'kind' => 'compose',
    'port' => 8000,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Sign in with the admin user and password below. Archives are private until you change that in the settings.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'ADMIN_USER', 'label' => 'Admin user', 'default' => 'admin'],
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  archivebox:
    image: archivebox/archivebox:latest
    restart: unless-stopped
    environment:
      ADMIN_USERNAME: ${ADMIN_USER}
      ADMIN_PASSWORD: ${ADMIN_PASSWORD}
      ALLOWED_HOSTS: "*"
      CSRF_TRUSTED_ORIGINS: ${APP_URL}
      PUBLIC_INDEX: "False"
      PUBLIC_SNAPSHOTS: "False"
      PUBLIC_ADD_VIEW: "False"
    ports:
      - "127.0.0.1:${PORT}:8000"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
