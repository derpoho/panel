<?php

return [
    'name' => 'NocoDB',
    'category' => 'Self-hosted',
    'description' => 'Spreadsheet-style interface over a database.',
    'icon' => 'nocodb',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/v1/health',
    'env' => [
        ['key' => 'NC_AUTH_JWT_SECRET', 'label' => 'JWT secret', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  nocodb:
    image: nocodb/nocodb:latest
    restart: unless-stopped
    environment:
      NC_AUTH_JWT_SECRET: ${NC_AUTH_JWT_SECRET}
      NC_PUBLIC_URL: ${APP_URL}
    ports:
      - "127.0.0.1:${PORT}:8080"
    volumes:
      - data:/usr/app/data
volumes:
  data:
YAML,
    ],
];
