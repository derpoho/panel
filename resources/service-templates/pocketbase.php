<?php

return [
    'name' => 'PocketBase',
    'category' => 'Databases',
    'description' => 'Backend in one file: a SQLite database, auth, file storage and an admin dashboard.',
    'icon' => 'pocketbase',
    'kind' => 'compose',
    'port' => 8090,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/health',
    'notes' => 'The dashboard is at /_/. On first start the logs print a link to create your first superuser, so open the logs and follow it. The API is at /api/.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  pocketbase:
    image: ghcr.io/muchobien/pocketbase:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:8090"
    volumes:
      - data:/pb_data
      - public:/pb_public
      - hooks:/pb_hooks
volumes:
  data:
  public:
  hooks:
YAML,
    ],
];
