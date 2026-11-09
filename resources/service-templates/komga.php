<?php

return [
    'name' => 'Komga',
    'category' => 'Self-hosted',
    'description' => 'Comics and manga server with a web reader and OPDS.',
    'icon' => 'book',
    'kind' => 'compose',
    'port' => 25600,
    'web' => true,
    'port_editable' => true,
    'health' => '/actuator/health',
    'notes' => 'Open it in the browser to create the admin account. Put your comics in the /data volume and add it as a library.',
    'env' => [
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  komga:
    image: gotson/komga:latest
    restart: unless-stopped
    environment:
      TZ: ${TIMEZONE}
    ports:
      - "127.0.0.1:${PORT}:25600"
    volumes:
      - config:/config
      - data:/data
volumes:
  config:
  data:
YAML,
    ],
];
