<?php

return [
    'name' => 'Collabora Online',
    'category' => 'Self-hosted',
    'description' => 'LibreOffice in the browser, for editing documents from Nextcloud and friends.',
    'icon' => 'collaboraonline',
    'kind' => 'compose',
    'port' => 9980,
    'web' => true,
    'port_editable' => true,
    'health' => '/hosting/discovery',
    'notes' => 'Set the allowed host to the address of the app that opens documents in it. The admin console is at /browser/dist/admin/admin.html.',
    'env' => [
        ['key' => 'ALLOWED_HOST', 'label' => 'Allowed host', 'default' => 'https://cloud.example.com:443', 'hint' => 'The app that uses it, like your Nextcloud, with https and :443.'],
        ['key' => 'ADMIN_USER', 'label' => 'Admin user', 'default' => 'admin'],
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  collabora:
    image: collabora/code:latest
    restart: unless-stopped
    cap_add:
      - MKNOD
    environment:
      aliasgroup1: ${ALLOWED_HOST}
      username: ${ADMIN_USER}
      password: ${ADMIN_PASSWORD}
      extra_params: --o:ssl.enable=false --o:ssl.termination=true
    ports:
      - "127.0.0.1:${PORT}:9980"
YAML,
    ],
];
