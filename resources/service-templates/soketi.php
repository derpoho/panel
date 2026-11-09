<?php

return [
    'name' => 'Soketi',
    'category' => 'Infrastructure',
    'description' => 'Pusher-compatible WebSockets server, works with Laravel Echo and pusher-js.',
    'icon' => 'chat',
    'kind' => 'compose',
    'port' => 6001,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Use the app ID, key and secret here as your Pusher credentials. Browsers connect through the public domain; backends on this machine publish to {bridge}:PORT.',
    'env' => [
        ['key' => 'APP_ID', 'label' => 'App ID', 'default' => 'app'],
        ['key' => 'APP_KEY', 'label' => 'App key', 'default' => '{random:20}', 'secret' => true],
        ['key' => 'APP_SECRET', 'label' => 'App secret', 'default' => '{random:32}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  soketi:
    image: quay.io/soketi/soketi:1.6-16-debian
    restart: unless-stopped
    environment:
      SOKETI_DEFAULT_APP_ID: ${APP_ID}
      SOKETI_DEFAULT_APP_KEY: ${APP_KEY}
      SOKETI_DEFAULT_APP_SECRET: ${APP_SECRET}
    ports:
      - "127.0.0.1:${PORT}:6001"
      - "${DOCKER_BRIDGE}:${PORT}:6001"
YAML,
    ],
];
