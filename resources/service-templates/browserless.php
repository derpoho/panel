<?php

return [
    'name' => 'Browserless',
    'category' => 'Infrastructure',
    'description' => 'Headless Chromium as a service for Puppeteer, Playwright, screenshots and PDFs.',
    'icon' => 'globe',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Connect with ws://{bridge}:PORT?token=YOUR_TOKEN from apps on this machine. Every request needs the token.',
    'env' => [
        ['key' => 'TOKEN', 'label' => 'Token', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'CONCURRENT', 'label' => 'Browsers at once', 'default' => '5'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  browserless:
    image: ghcr.io/browserless/chromium:latest
    restart: unless-stopped
    environment:
      TOKEN: ${TOKEN}
      CONCURRENT: ${CONCURRENT}
    ports:
      - "127.0.0.1:${PORT}:3000"
      - "${DOCKER_BRIDGE}:${PORT}:3000"
YAML,
    ],
];
