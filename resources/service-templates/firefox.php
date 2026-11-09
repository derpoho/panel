<?php

return [
    'name' => 'Firefox',
    'category' => 'Self-hosted',
    'description' => 'Firefox running on your server, used from any browser.',
    'icon' => 'firefoxbrowser',
    'kind' => 'compose',
    'port' => 5800,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Enter the password below when the page asks. Only the first 8 characters count. Profile and downloads live in the config volume.',
    'env' => [
        ['key' => 'VNC_PASSWORD', 'label' => 'Password', 'default' => '{random:8}', 'secret' => true],
        ['key' => 'TZ', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  firefox:
    image: jlesage/firefox:latest
    restart: unless-stopped
    shm_size: 2gb
    environment:
      TZ: ${TZ}
      VNC_PASSWORD: ${VNC_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:5800"
    volumes:
      - config:/config
volumes:
  config:
YAML,
    ],
];
