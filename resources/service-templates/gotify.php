<?php

return [
    'name' => 'Gotify',
    'category' => 'Self-hosted',
    'description' => 'Send and receive push notifications through a simple REST API.',
    'icon' => 'chat',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/health',
    'notes' => 'Sign in as admin with the password you set here. Make an app in the dashboard to get a token for sending messages.',
    'env' => [
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:20}', 'secret' => true],
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  gotify:
    image: gotify/server:latest
    restart: unless-stopped
    environment:
      TZ: ${TIMEZONE}
      GOTIFY_DEFAULTUSER_NAME: admin
      GOTIFY_DEFAULTUSER_PASS: ${ADMIN_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - data:/app/data
volumes:
  data:
YAML,
    ],
];
