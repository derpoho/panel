<?php

return [
    'name' => 'ESPHome',
    'category' => 'Self-hosted',
    'description' => 'Write configs for your ESP32 and ESP8266 boards and update them over the air.',
    'icon' => 'esphome',
    'kind' => 'compose',
    'port' => 6052,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Sign in with the username and password you set here. Runs on a bridge network, so devices are checked by ping instead of mDNS, and the first flash of a new board is easiest over USB from your own computer.',
    'env' => [
        ['key' => 'DASHBOARD_USER', 'label' => 'Dashboard username', 'default' => 'admin'],
        ['key' => 'DASHBOARD_PASSWORD', 'label' => 'Dashboard password', 'default' => '{random:20}', 'secret' => true],
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  esphome:
    image: ghcr.io/esphome/esphome:latest
    restart: unless-stopped
    environment:
      TZ: ${TIMEZONE}
      USERNAME: ${DASHBOARD_USER}
      PASSWORD: ${DASHBOARD_PASSWORD}
      ESPHOME_DASHBOARD_USE_PING: "true"
    ports:
      - "127.0.0.1:${PORT}:6052"
    volumes:
      - config:/config
volumes:
  config:
YAML,
    ],
];
