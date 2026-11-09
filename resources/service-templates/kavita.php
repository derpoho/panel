<?php

return [
    'name' => 'Kavita',
    'category' => 'Self-hosted',
    'description' => 'Reading server for manga, comics and ebooks.',
    'icon' => 'book',
    'kind' => 'compose',
    'port' => 5000,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/health',
    'notes' => 'Open it in the browser to create the admin account. Put your books in the /library volume and add it as a library.',
    'env' => [
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  kavita:
    image: jvmilazz0/kavita:latest
    restart: unless-stopped
    environment:
      TZ: ${TIMEZONE}
    ports:
      - "127.0.0.1:${PORT}:5000"
    volumes:
      - config:/kavita/config
      - library:/library
volumes:
  config:
  library:
YAML,
    ],
];
