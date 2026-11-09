<?php

return [
    'name' => 'Selenium',
    'category' => 'Infrastructure',
    'description' => 'Standalone Selenium with Chrome, for running browser tests remotely.',
    'icon' => 'selenium',
    'kind' => 'compose',
    'port' => 4444,
    'web' => true,
    'port_editable' => true,
    'health' => '/status',
    'notes' => 'Point your tests at http://{bridge}:PORT from this machine. Watch the browser live with noVNC on localhost:7900, password secret. Chrome images are x86 only.',
    'env' => [
        ['key' => 'MAX_SESSIONS', 'label' => 'Sessions at once', 'default' => '2'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  selenium:
    image: selenium/standalone-chrome:latest
    restart: unless-stopped
    shm_size: 2gb
    environment:
      SE_NODE_MAX_SESSIONS: ${MAX_SESSIONS}
      SE_NODE_OVERRIDE_MAX_SESSIONS: "true"
    ports:
      - "127.0.0.1:${PORT}:4444"
      - "${DOCKER_BRIDGE}:${PORT}:4444"
      - "127.0.0.1:7900:7900"
YAML,
    ],
];
