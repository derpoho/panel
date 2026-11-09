<?php

return [
    'name' => 'Etherpad',
    'category' => 'Self-hosted',
    'description' => 'Simple shared text editor for writing together, with Postgres.',
    'icon' => 'notebook',
    'kind' => 'compose',
    'port' => 9001,
    'web' => true,
    'port_editable' => true,
    'health' => '/health',
    'notes' => 'The admin area is at /admin, signing in as admin with the admin password below.',
    'env' => [
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  etherpad:
    image: etherpad/etherpad:latest
    restart: unless-stopped
    depends_on: [db]
    environment:
      NODE_ENV: production
      ADMIN_PASSWORD: ${ADMIN_PASSWORD}
      DB_TYPE: postgres
      DB_HOST: db
      DB_PORT: 5432
      DB_NAME: etherpad
      DB_USER: etherpad
      DB_PASS: ${DB_PASSWORD}
      TRUST_PROXY: "true"
    ports:
      - "127.0.0.1:${PORT}:9001"
    volumes:
      - plugins:/opt/etherpad-lite/src/plugin_packages
      - var:/opt/etherpad-lite/var
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: etherpad
      POSTGRES_USER: etherpad
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  plugins:
  var:
  db:
YAML,
    ],
];
