<?php

return [
    'name' => 'Continuwuity',
    'category' => 'Self-hosted',
    'description' => 'A light Matrix homeserver in Rust, the maintained fork of Conduit.',
    'icon' => 'matrix',
    'kind' => 'compose',
    'port' => 8008,
    'web' => true,
    'port_editable' => true,
    'health' => '/_matrix/client/versions',
    'notes' => 'The server name cannot change later without wiping the data. On first start a one-time registration token is printed in the service logs: register with it in any Matrix client and that account becomes the admin. Clients and federation are both served on 443 through .well-known.',
    'env' => [
        ['key' => 'SERVER_NAME', 'label' => 'Server name', 'default' => '', 'required' => true, 'hint' => 'The domain this service is served on, like matrix.example.com. User IDs end in it.'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  continuwuity:
    image: forgejo.ellis.link/continuwuation/continuwuity:latest
    restart: unless-stopped
    environment:
      CONTINUWUITY_SERVER_NAME: ${SERVER_NAME}
      CONTINUWUITY_DATABASE_PATH: /var/lib/continuwuity
      CONTINUWUITY_ADDRESS: 0.0.0.0
      CONTINUWUITY_PORT: 8008
      CONTINUWUITY_WELL_KNOWN__CLIENT: https://${SERVER_NAME}
      CONTINUWUITY_WELL_KNOWN__SERVER: ${SERVER_NAME}:443
    ports:
      - "127.0.0.1:${PORT}:8008"
    volumes:
      - data:/var/lib/continuwuity
volumes:
  data:
YAML,
    ],
];
