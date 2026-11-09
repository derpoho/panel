<?php

return [
    'name' => 'FreshRSS',
    'category' => 'Self-hosted',
    'description' => 'RSS and Atom feed reader, refreshing feeds every 20 minutes.',
    'icon' => 'freshrss',
    'kind' => 'compose',
    'port' => 8083,
    'web' => true,
    'port_editable' => true,
    'health' => '/i/',
    'notes' => 'Finish the install in the browser, choosing SQLite for the database.',
    'env' => [
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  freshrss:
    image: freshrss/freshrss:latest
    restart: unless-stopped
    environment:
      TZ: ${TIMEZONE}
      CRON_MIN: "*/20"
      TRUSTED_PROXY: 172.16.0.0/12 192.168.0.0/16 10.0.0.0/8
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - data:/var/www/FreshRSS/data
      - extensions:/var/www/FreshRSS/extensions
volumes:
  data:
  extensions:
YAML,
    ],
];
