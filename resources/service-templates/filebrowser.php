<?php

return [
    'name' => 'File Browser',
    'category' => 'Self-hosted',
    'description' => 'Upload, download and manage files from the browser.',
    'icon' => 'folder',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/health',
    'notes' => 'Sign in as admin. The first password is printed in the service logs, so change it once you are in.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  filebrowser:
    image: filebrowser/filebrowser:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - files:/srv
      - database:/database
      - config:/config
volumes:
  files:
  database:
  config:
YAML,
    ],
];
