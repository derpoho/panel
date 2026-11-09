<?php

return [
    'name' => 'n8n',
    'category' => 'Self-hosted',
    'description' => 'Workflow automation with a visual editor.',
    'icon' => 'n8n',
    'kind' => 'compose',
    'port' => 5678,
    'web' => true,
    'port_editable' => true,
    'health' => '/healthz',
    'env' => [
        ['key' => 'N8N_ENCRYPTION_KEY', 'label' => 'Encryption key', 'default' => '{random:32}', 'secret' => true, 'hint' => 'Keep it: n8n encrypts saved credentials with it.'],
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  n8n:
    image: docker.n8n.io/n8nio/n8n:latest
    restart: unless-stopped
    environment:
      N8N_ENCRYPTION_KEY: ${N8N_ENCRYPTION_KEY}
      WEBHOOK_URL: ${APP_URL}
      N8N_PROXY_HOPS: "1"
      GENERIC_TIMEZONE: ${TIMEZONE}
    ports:
      - "127.0.0.1:${PORT}:5678"
    volumes:
      - data:/home/node/.n8n
volumes:
  data:
YAML,
    ],
];
