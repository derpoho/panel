<?php

return [
    'name' => 'Kutt',
    'category' => 'Self-hosted',
    'description' => 'A clean URL shortener with custom domains and link stats.',
    'icon' => 'globe',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Open it in the browser to create the admin account.',
    'env' => [
        ['key' => 'DOMAIN', 'label' => 'Domain', 'default' => '', 'required' => true, 'hint' => 'Just the host name, like kutt.example.com'],
        ['key' => 'JWT_SECRET', 'label' => 'JWT secret', 'default' => '{random:48}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  kutt:
    image: kutt/kutt:latest
    restart: unless-stopped
    environment:
      DEFAULT_DOMAIN: ${DOMAIN}
      JWT_SECRET: ${JWT_SECRET}
      TRUST_PROXY: "true"
      DB_FILENAME: /var/lib/kutt/data.sqlite
    ports:
      - "127.0.0.1:${PORT}:3000"
    volumes:
      - data:/var/lib/kutt
      - custom:/kutt/custom
volumes:
  data:
  custom:
YAML,
    ],
];
