<?php

return [
    'name' => 'SilverBullet',
    'category' => 'Self-hosted',
    'description' => 'Markdown notebook you can script, kept as plain files.',
    'icon' => 'notebook',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'env' => [
        ['key' => 'ADMIN_USER', 'label' => 'User', 'default' => 'admin'],
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  silverbullet:
    image: ghcr.io/silverbulletmd/silverbullet:latest
    restart: unless-stopped
    environment:
      SB_USER: ${ADMIN_USER}:${ADMIN_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:3000"
    volumes:
      - space:/space
volumes:
  space:
YAML,
    ],
];
