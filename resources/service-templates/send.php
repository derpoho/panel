<?php

return [
    'name' => 'Send',
    'category' => 'Self-hosted',
    'description' => 'Share files with encrypted links that expire, the community fork of Firefox Send.',
    'icon' => 'lock',
    'kind' => 'compose',
    'port' => 1443,
    'web' => true,
    'port_editable' => true,
    'health' => '/__lbheartbeat__',
    'notes' => 'No accounts. Add a domain with HTTPS, the browser needs it for encryption.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'MAX_FILE_SIZE', 'label' => 'Max file size (bytes)', 'default' => '2147483648'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  send:
    image: registry.gitlab.com/timvisee/send:latest
    restart: unless-stopped
    depends_on: [redis]
    environment:
      BASE_URL: ${APP_URL}
      REDIS_HOST: redis
      FILE_DIR: /uploads
      MAX_FILE_SIZE: ${MAX_FILE_SIZE}
    ports:
      - "127.0.0.1:${PORT}:1443"
    volumes:
      - uploads:/uploads
  redis:
    image: redis:7-alpine
    restart: unless-stopped
    volumes:
      - redis:/data
volumes:
  uploads:
  redis:
YAML,
    ],
];
