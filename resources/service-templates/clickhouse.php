<?php

return [
    'name' => 'ClickHouse',
    'category' => 'Databases',
    'description' => 'Column-oriented analytics database. HTTP interface on the chosen port.',
    'icon' => 'clickhouse',
    'kind' => 'compose',
    'port' => 8123,
    'web' => false,
    'port_editable' => true,
    'health' => '/ping',
    'notes' => 'Apps you deploy on this machine connect through {bridge}, at the port shown above.',
    'env' => [
        ['key' => 'CLICKHOUSE_DB', 'label' => 'Database', 'default' => 'default'],
        ['key' => 'CLICKHOUSE_USER', 'label' => 'User', 'default' => 'app'],
        ['key' => 'CLICKHOUSE_PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  clickhouse:
    image: clickhouse/clickhouse-server:24.8
    restart: unless-stopped
    environment:
      CLICKHOUSE_DB: ${CLICKHOUSE_DB}
      CLICKHOUSE_USER: ${CLICKHOUSE_USER}
      CLICKHOUSE_PASSWORD: ${CLICKHOUSE_PASSWORD}
      CLICKHOUSE_DEFAULT_ACCESS_MANAGEMENT: "1"
    ulimits:
      nofile:
        soft: 262144
        hard: 262144
    ports:
      - "127.0.0.1:${PORT}:8123"
      - "${DOCKER_BRIDGE}:${PORT}:8123"
    volumes:
      - data:/var/lib/clickhouse
volumes:
  data:
YAML,
    ],
];
