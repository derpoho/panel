<?php

return [
    'name' => 'Wallos',
    'category' => 'Self-hosted',
    'description' => 'Keep track of your subscriptions and what they cost you each month.',
    'icon' => 'chart',
    'kind' => 'compose',
    'port' => 8282,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Open it in the browser to create your account.',
    'env' => [
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  wallos:
    image: bellamy/wallos:latest
    restart: unless-stopped
    environment:
      TZ: ${TIMEZONE}
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - db:/var/www/html/db
      - logos:/var/www/html/images/uploads/logos
volumes:
  db:
  logos:
YAML,
    ],
];
