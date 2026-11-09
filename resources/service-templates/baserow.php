<?php

return [
    'name' => 'Baserow',
    'category' => 'Self-hosted',
    'description' => 'Airtable-style spreadsheet database you can build apps on.',
    'icon' => 'baserow',
    'kind' => 'compose',
    'port' => 80,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/_health/',
    'notes' => 'Open it in the browser and sign up; the first account becomes the admin. The first start takes a few minutes.',
    'env' => [
        ['key' => 'PUBLIC_URL', 'label' => 'Public URL', 'default' => '{url}'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  baserow:
    image: baserow/baserow:latest
    restart: unless-stopped
    environment:
      BASEROW_PUBLIC_URL: ${PUBLIC_URL}
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - data:/baserow/data
volumes:
  data:
YAML,
    ],
];
