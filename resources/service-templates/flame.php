<?php

return [
    'name' => 'Flame',
    'category' => 'Self-hosted',
    'description' => 'A simple start page for your apps and bookmarks.',
    'icon' => 'bookmark',
    'kind' => 'compose',
    'port' => 5005,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Sign in from the settings page with the password from here to edit apps and bookmarks.',
    'env' => [
        ['key' => 'PASSWORD', 'label' => 'Password', 'default' => '{random:16}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  flame:
    image: pawelmalak/flame:latest
    restart: unless-stopped
    environment:
      PASSWORD: ${PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:5005"
    volumes:
      - data:/app/data
volumes:
  data:
YAML,
    ],
];
