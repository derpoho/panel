<?php

return [
    'name' => 'Pocket ID',
    'category' => 'Infrastructure',
    'description' => 'Simple OIDC provider where everyone signs in with passkeys.',
    'icon' => 'shield',
    'kind' => 'compose',
    'port' => 1411,
    'web' => true,
    'port_editable' => true,
    'health' => '/healthz',
    'notes' => 'Passkeys need HTTPS, so give it a domain. Go to /setup to create the admin and register a passkey.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'ENCRYPTION_KEY', 'label' => 'Encryption key', 'default' => '{random:32}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  pocket-id:
    image: ghcr.io/pocket-id/pocket-id:latest
    restart: unless-stopped
    environment:
      APP_URL: ${APP_URL}
      TRUST_PROXY: "true"
      ENCRYPTION_KEY: ${ENCRYPTION_KEY}
    ports:
      - "127.0.0.1:${PORT}:1411"
    volumes:
      - data:/app/data
volumes:
  data:
YAML,
    ],
];
