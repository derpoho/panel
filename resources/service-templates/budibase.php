<?php

return [
    'name' => 'Budibase',
    'category' => 'Self-hosted',
    'description' => 'Low-code platform for forms, portals and internal apps.',
    'icon' => 'budibase',
    'kind' => 'compose',
    'port' => 80,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Open it in the browser to create the admin account. Everything, including its own database, runs in this one container.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  budibase:
    image: budibase/budibase:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
