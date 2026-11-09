<?php

return [
    'name' => 'Element',
    'category' => 'Self-hosted',
    'description' => 'The Matrix chat client in your browser, pointed at your own homeserver.',
    'icon' => 'element',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/config.json',
    'notes' => 'Set the homeserver URL to your Synapse or Continuwuity address. People can still pick a different server on the sign-in page.',
    'env' => [
        ['key' => 'HOMESERVER_URL', 'label' => 'Homeserver URL', 'default' => 'https://matrix.org', 'hint' => 'The address of your Matrix homeserver, like https://matrix.example.com'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  element:
    image: vectorim/element-web:latest
    restart: unless-stopped
    user: root
    # Write config.json from the homeserver URL on every start.
    entrypoint:
      - sh
      - -c
      - |
        printf '{"default_server_config":{"m.homeserver":{"base_url":"%s"}},"disable_guests":true,"show_labs_settings":false}\n' "${HOMESERVER_URL}" > /app/config.json
        exec /docker-entrypoint.sh nginx -g 'daemon off;'
    ports:
      - "127.0.0.1:${PORT}:80"
YAML,
    ],
];
