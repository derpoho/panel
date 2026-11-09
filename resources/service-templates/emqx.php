<?php

return [
    'name' => 'EMQX',
    'category' => 'Infrastructure',
    'description' => 'MQTT broker for IoT devices and apps, with a web dashboard.',
    'icon' => 'server',
    'kind' => 'compose',
    'port' => 18083,
    'web' => true,
    'port_editable' => true,
    'health' => '/status',
    'notes' => 'Sign in to the dashboard as admin with the password you set here. MQTT listens on {bridge} and 127.0.0.1 at the MQTT port. Devices outside this machine need that port opened, and authentication turned on first.',
    'env' => [
        ['key' => 'DASHBOARD_PASSWORD', 'label' => 'Dashboard password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'MQTT_PORT', 'label' => 'MQTT port', 'default' => '1883'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  emqx:
    image: emqx/emqx:latest
    restart: unless-stopped
    environment:
      EMQX_NODE__NAME: emqx@127.0.0.1
      EMQX_DASHBOARD__DEFAULT_PASSWORD: ${DASHBOARD_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:18083"
      - "127.0.0.1:${MQTT_PORT}:1883"
      - "${DOCKER_BRIDGE}:${MQTT_PORT}:1883"
    volumes:
      - data:/opt/emqx/data
      - log:/opt/emqx/log
volumes:
  data:
  log:
YAML,
    ],
];
