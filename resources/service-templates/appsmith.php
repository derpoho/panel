<?php

return [
    'name' => 'Appsmith',
    'category' => 'Self-hosted',
    'description' => 'Build internal tools and admin panels on top of your databases and APIs.',
    'icon' => 'appsmith',
    'kind' => 'compose',
    'port' => 80,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/v1/health',
    'notes' => 'Open it in the browser to create the admin account. It wants about 4 GB of memory and takes a few minutes to start.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  appsmith:
    image: appsmith/appsmith-ce:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - stacks:/appsmith-stacks
volumes:
  stacks:
YAML,
    ],
];
