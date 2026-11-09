<?php

return [
    'name' => 'flatnotes',
    'category' => 'Self-hosted',
    'description' => 'Markdown notes kept as plain files, with search and tags.',
    'icon' => 'notebook',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/health',
    'env' => [
        ['key' => 'ADMIN_USER', 'label' => 'User', 'default' => 'admin'],
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'SECRET_KEY', 'label' => 'Secret key', 'default' => '{random:32}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  flatnotes:
    image: dullage/flatnotes:latest
    restart: unless-stopped
    environment:
      PUID: 1000
      PGID: 1000
      FLATNOTES_AUTH_TYPE: password
      FLATNOTES_USERNAME: ${ADMIN_USER}
      FLATNOTES_PASSWORD: ${ADMIN_PASSWORD}
      FLATNOTES_SECRET_KEY: ${SECRET_KEY}
    ports:
      - "127.0.0.1:${PORT}:8080"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
