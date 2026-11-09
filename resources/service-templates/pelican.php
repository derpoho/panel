<?php

return [
    'name' => 'Pelican',
    'category' => 'Game servers',
    'description' => 'Game server panel, the successor to Pterodactyl. With SQLite built in.',
    'icon' => 'pelican',
    'kind' => 'compose',
    'port' => 8000,
    'web' => true,
    'port_editable' => true,
    'health' => '/up',
    'notes' => 'Finish the installer at /installer in the browser to create the admin account. Game servers run on Wings nodes, which you add from the panel.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Panel URL', 'default' => '{url}', 'hint' => 'Wings and its links use this, so redeploy if the domain changes.'],
        ['key' => 'APP_KEY', 'label' => 'App key', 'default' => '{base64:32}', 'secret' => true, 'hint' => 'Keep it: Pelican encrypts node tokens and other secrets with it.'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  web:
    image: ghcr.io/pelican/panel:latest
    restart: unless-stopped
    environment:
      APP_URL: ${APP_URL}
      APP_KEY: ${APP_KEY}
      APP_ENV: production
      APP_DEBUG: "false"
      XDG_DATA_HOME: /pelican-data
      BEHIND_PROXY: "true"
      TRUSTED_PROXIES: 127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16
      MAIL_DRIVER: log
    ports:
      - "127.0.0.1:${PORT}:80"
    extra_hosts:
      - "host.docker.internal:host-gateway"
    volumes:
      - data:/pelican-data
      - logs:/var/www/html/storage/logs
volumes:
  data:
  logs:
YAML,
    ],
];
