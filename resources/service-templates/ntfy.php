<?php

return [
    'name' => 'ntfy',
    'category' => 'Self-hosted',
    'description' => 'Push notifications to your phone or desktop with a simple HTTP POST.',
    'icon' => 'ntfy',
    'kind' => 'compose',
    'port' => 8087,
    'web' => true,
    'port_editable' => true,
    'health' => '/v1/health',
    'notes' => 'Anyone who knows a topic name can read and post to it, so pick names that are hard to guess.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  ntfy:
    image: binwiederhier/ntfy:latest
    restart: unless-stopped
    command: ["serve"]
    environment:
      NTFY_BASE_URL: ${APP_URL}
      NTFY_BEHIND_PROXY: "true"
      NTFY_CACHE_FILE: /var/cache/ntfy/cache.db
      NTFY_ATTACHMENT_CACHE_DIR: /var/cache/ntfy/attachments
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - cache:/var/cache/ntfy
volumes:
  cache:
YAML,
    ],
];
