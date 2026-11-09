<?php

return [
    'name' => 'Metabase',
    'category' => 'Self-hosted',
    'description' => 'Dashboards and questions over your databases.',
    'icon' => 'metabase',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/health',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  metabase:
    image: metabase/metabase:latest
    restart: unless-stopped
    environment:
      MB_DB_FILE: /metabase-data/metabase.db
      MB_SITE_URL: ${APP_URL}
    ports:
      - "127.0.0.1:${PORT}:3000"
    volumes:
      - data:/metabase-data
volumes:
  data:
YAML,
    ],
];
