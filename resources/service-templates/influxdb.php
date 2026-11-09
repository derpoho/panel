<?php

return [
    'name' => 'InfluxDB',
    'category' => 'Databases',
    'description' => 'Time-series database for metrics and events, with a web UI.',
    'icon' => 'influxdb',
    'kind' => 'compose',
    'port' => 8086,
    'web' => true,
    'port_editable' => true,
    'health' => '/health',
    'notes' => 'Set up on first boot with the user, organisation, bucket and token below. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'INFLUX_USER', 'label' => 'Admin user', 'default' => 'admin'],
        ['key' => 'INFLUX_PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'INFLUX_ORG', 'label' => 'Organisation', 'default' => 'serveros'],
        ['key' => 'INFLUX_BUCKET', 'label' => 'Bucket', 'default' => 'default'],
        ['key' => 'INFLUX_TOKEN', 'label' => 'Admin token', 'default' => '{random:48}', 'secret' => true, 'hint' => 'Clients write and query with it.'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  influxdb:
    image: influxdb:2
    restart: unless-stopped
    environment:
      DOCKER_INFLUXDB_INIT_MODE: setup
      DOCKER_INFLUXDB_INIT_USERNAME: ${INFLUX_USER}
      DOCKER_INFLUXDB_INIT_PASSWORD: ${INFLUX_PASSWORD}
      DOCKER_INFLUXDB_INIT_ORG: ${INFLUX_ORG}
      DOCKER_INFLUXDB_INIT_BUCKET: ${INFLUX_BUCKET}
      DOCKER_INFLUXDB_INIT_ADMIN_TOKEN: ${INFLUX_TOKEN}
    ports:
      - "127.0.0.1:${PORT}:8086"
      - "${DOCKER_BRIDGE}:${PORT}:8086"
    volumes:
      - data:/var/lib/influxdb2
      - config:/etc/influxdb2
volumes:
  data:
  config:
YAML,
    ],
];
