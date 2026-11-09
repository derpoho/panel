<?php

return [
    'name' => 'Centrifugo',
    'category' => 'Infrastructure',
    'description' => 'Real-time messaging server for WebSockets and SSE, with an admin UI.',
    'icon' => 'chat',
    'kind' => 'compose',
    'port' => 8000,
    'web' => true,
    'port_editable' => true,
    'health' => '/health',
    'notes' => 'Browsers connect to /connection/websocket. Your backend publishes through the HTTP API at {bridge}:PORT/api with the API key. The admin UI is at the root, signed in with the admin password.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Allowed origin', 'default' => '{url}', 'hint' => 'Your site address, space separated for more than one'],
        ['key' => 'TOKEN_SECRET', 'label' => 'Token HMAC secret', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'API_KEY', 'label' => 'HTTP API key', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'ADMIN_SECRET', 'label' => 'Admin secret', 'default' => '{random:32}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  centrifugo:
    image: centrifugo/centrifugo:v6
    restart: unless-stopped
    command: centrifugo
    environment:
      CENTRIFUGO_CLIENT_TOKEN_HMAC_SECRET_KEY: ${TOKEN_SECRET}
      CENTRIFUGO_CLIENT_ALLOWED_ORIGINS: ${APP_URL}
      CENTRIFUGO_HTTP_API_KEY: ${API_KEY}
      CENTRIFUGO_ADMIN_ENABLED: "true"
      CENTRIFUGO_ADMIN_PASSWORD: ${ADMIN_PASSWORD}
      CENTRIFUGO_ADMIN_SECRET: ${ADMIN_SECRET}
      CENTRIFUGO_HEALTH_ENABLED: "true"
    ports:
      - "127.0.0.1:${PORT}:8000"
      - "${DOCKER_BRIDGE}:${PORT}:8000"
YAML,
    ],
];
