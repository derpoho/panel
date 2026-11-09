<?php

return [
    'name' => 'ONLYOFFICE Docs',
    'category' => 'Self-hosted',
    'description' => 'Office document editor you plug into Nextcloud and other apps.',
    'icon' => 'onlyoffice',
    'kind' => 'compose',
    'port' => 8085,
    'web' => true,
    'port_editable' => true,
    'health' => '/healthcheck',
    'notes' => 'Point your Nextcloud, ownCloud or other app at its public URL and give it the JWT secret below. The first start takes a few minutes.',
    'env' => [
        ['key' => 'JWT_SECRET', 'label' => 'JWT secret', 'default' => '{random:32}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  onlyoffice:
    image: onlyoffice/documentserver:latest
    restart: unless-stopped
    environment:
      JWT_ENABLED: "true"
      JWT_SECRET: ${JWT_SECRET}
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - data:/var/www/onlyoffice/Data
      - lib:/var/lib/onlyoffice
      - logs:/var/log/onlyoffice
      - db:/var/lib/postgresql
volumes:
  data:
  lib:
  logs:
  db:
YAML,
    ],
];
