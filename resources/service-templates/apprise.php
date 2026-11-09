<?php

return [
    'name' => 'Apprise API',
    'category' => 'Self-hosted',
    'description' => 'One API that sends notifications to Slack, Discord, Telegram, email and loads more.',
    'icon' => 'chat',
    'kind' => 'compose',
    'port' => 8000,
    'web' => true,
    'port_editable' => true,
    'health' => '/status',
    'notes' => 'There is no login, so anyone who can reach it can send through your saved configs. Keep it private or put it behind access control.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  apprise:
    image: caronc/apprise:latest
    restart: unless-stopped
    environment:
      APPRISE_STATEFUL_MODE: simple
    ports:
      - "127.0.0.1:${PORT}:8000"
    volumes:
      - config:/config
      - attach:/attach
volumes:
  config:
  attach:
YAML,
    ],
];
