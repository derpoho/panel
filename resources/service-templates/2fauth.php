<?php

return [
    'name' => '2FAuth',
    'category' => 'Self-hosted',
    'description' => 'Keep your two-factor codes in one web app instead of on your phone.',
    'icon' => 'lock',
    'kind' => 'compose',
    'port' => 8000,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Open it in the browser to register. The first account becomes the admin.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'APP_KEY', 'label' => 'App key', 'default' => '{random:32}', 'secret' => true, 'hint' => 'Exactly 32 characters'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  2fauth:
    image: 2fauth/2fauth:latest
    restart: unless-stopped
    environment:
      APP_URL: ${APP_URL}
      APP_KEY: ${APP_KEY}
      TRUSTED_PROXIES: "*"
    ports:
      - "127.0.0.1:${PORT}:8000"
    volumes:
      - data:/2fauth
volumes:
  data:
YAML,
    ],
];
