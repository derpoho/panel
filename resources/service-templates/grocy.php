<?php

return [
    'name' => 'Grocy',
    'category' => 'Self-hosted',
    'description' => 'Track groceries, what is in the fridge, chores and household stock.',
    'icon' => 'grocy',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'The first login is admin / admin. Change it straight away.',
    'env' => [
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  grocy:
    image: lscr.io/linuxserver/grocy:latest
    restart: unless-stopped
    environment:
      PUID: "1000"
      PGID: "1000"
      TZ: ${TIMEZONE}
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - config:/config
volumes:
  config:
YAML,
    ],
];
