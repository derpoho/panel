<?php

return [
    'name' => 'Whoogle',
    'category' => 'Self-hosted',
    'description' => 'Google search results without the ads, tracking or JavaScript.',
    'icon' => 'search',
    'kind' => 'compose',
    'port' => 5000,
    'web' => true,
    'port_editable' => true,
    'health' => '/healthz',
    'notes' => 'Anyone with the address can search through it, so set the username and password if it is public.',
    'env' => [
        ['key' => 'WHOOGLE_USER', 'label' => 'Username (optional)', 'default' => ''],
        ['key' => 'WHOOGLE_PASS', 'label' => 'Password (optional)', 'default' => '', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  whoogle:
    image: benbusby/whoogle-search:latest
    restart: unless-stopped
    environment:
      WHOOGLE_USER: ${WHOOGLE_USER}
      WHOOGLE_PASS: ${WHOOGLE_PASS}
    ports:
      - "127.0.0.1:${PORT}:5000"
YAML,
    ],
];
