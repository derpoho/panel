<?php

return [
    'name' => 'DocuSeal',
    'category' => 'Self-hosted',
    'description' => 'Send documents out for signing and fill PDF forms online.',
    'icon' => 'pdf',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Open it in the browser to create the admin account.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  docuseal:
    image: docuseal/docuseal:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:3000"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
