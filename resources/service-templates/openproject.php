<?php

return [
    'name' => 'OpenProject',
    'category' => 'Self-hosted',
    'description' => 'Project management with tasks, Gantt charts, boards and time tracking.',
    'icon' => 'openproject',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/health_checks/default',
    'notes' => 'The first login is admin / admin, and it asks you to change the password. The first start takes a few minutes while it sets up its database.',
    'env' => [
        ['key' => 'DOMAIN', 'label' => 'Domain', 'default' => '', 'required' => true, 'hint' => 'Just the host name, like projects.example.com'],
        ['key' => 'SECRET_KEY_BASE', 'label' => 'Secret key', 'default' => '{random:64}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  openproject:
    image: openproject/openproject:17
    restart: unless-stopped
    environment:
      OPENPROJECT_HOST__NAME: ${DOMAIN}
      OPENPROJECT_HTTPS: "true"
      OPENPROJECT_SECRET_KEY_BASE: ${SECRET_KEY_BASE}
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - pgdata:/var/openproject/pgdata
      - assets:/var/openproject/assets
volumes:
  pgdata:
  assets:
YAML,
    ],
];
