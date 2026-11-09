<?php

return [
    'name' => 'SQLPad',
    'category' => 'Infrastructure',
    'description' => 'Write SQL in the browser, see results and charts, share queries.',
    'icon' => 'database',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Sign in with the admin email and password you set here. For databases on this machine, connect to {bridge} and their port.',
    'env' => [
        ['key' => 'ADMIN_EMAIL', 'label' => 'Admin email', 'default' => '', 'required' => true],
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  sqlpad:
    image: sqlpad/sqlpad:latest
    restart: unless-stopped
    environment:
      SQLPAD_ADMIN: ${ADMIN_EMAIL}
      SQLPAD_ADMIN_PASSWORD: ${ADMIN_PASSWORD}
      SQLPAD_DB_PATH: /var/lib/sqlpad
      SQLPAD_PORT: 3000
    ports:
      - "127.0.0.1:${PORT}:3000"
    volumes:
      - data:/var/lib/sqlpad
volumes:
  data:
YAML,
    ],
];
