<?php

return [
    'name' => 'Homebridge',
    'category' => 'Self-hosted',
    'description' => 'Bring devices that do not support HomeKit into the Apple Home app.',
    'icon' => 'homebridge',
    'kind' => 'compose',
    'port' => 8581,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Open it in the browser to create the admin account. Runs on a bridge network, so pairing with the Home app over mDNS only works if this machine is on the same network as your Apple devices.',
    'env' => [
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  homebridge:
    image: homebridge/homebridge:latest
    restart: unless-stopped
    environment:
      TZ: ${TIMEZONE}
    ports:
      - "127.0.0.1:${PORT}:8581"
    volumes:
      - data:/homebridge
volumes:
  data:
YAML,
    ],
];
