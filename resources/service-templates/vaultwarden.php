<?php

return [
    'name' => 'Vaultwarden',
    'category' => 'Self-hosted',
    'description' => 'Bitwarden-compatible password manager. Needs HTTPS, so add a domain.',
    'icon' => 'vaultwarden',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/alive',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'ADMIN_TOKEN', 'label' => 'Admin token', 'default' => '{random:48}', 'secret' => true, 'hint' => 'Opens /admin. Keep it safe.'],
        ['key' => 'SIGNUPS_ALLOWED', 'label' => 'Allow sign-ups', 'default' => 'true', 'hint' => 'Set to false once your accounts exist.'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  vaultwarden:
    image: vaultwarden/server:latest
    restart: unless-stopped
    environment:
      DOMAIN: ${APP_URL}
      ADMIN_TOKEN: ${ADMIN_TOKEN}
      SIGNUPS_ALLOWED: ${SIGNUPS_ALLOWED}
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
