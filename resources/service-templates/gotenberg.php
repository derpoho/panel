<?php

return [
    'name' => 'Gotenberg',
    'category' => 'Infrastructure',
    'description' => 'API that turns HTML, Markdown and Office files into PDFs.',
    'icon' => 'pdf',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/health',
    'notes' => 'Apps on this machine call it at http://{bridge}:PORT. It has no login, so it only listens locally and on the Docker bridge.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  gotenberg:
    image: gotenberg/gotenberg:8
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:3000"
      - "${DOCKER_BRIDGE}:${PORT}:3000"
YAML,
    ],
];
