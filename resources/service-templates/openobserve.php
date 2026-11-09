<?php

return [
    'name' => 'OpenObserve',
    'category' => 'Infrastructure',
    'description' => 'Logs, metrics and traces in one place, with a web UI and cheap storage.',
    'icon' => 'chart',
    'kind' => 'compose',
    'port' => 5080,
    'web' => true,
    'port_editable' => true,
    'health' => '/healthz',
    'notes' => 'Sign in with the admin email and password from your settings. Apps on this machine send logs and OTLP data to http://{bridge}:PORT/api/default.',
    'env' => [
        ['key' => 'ADMIN_EMAIL', 'label' => 'Admin email', 'default' => 'admin@example.com'],
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:20}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  openobserve:
    image: public.ecr.aws/zinclabs/openobserve:latest
    restart: unless-stopped
    environment:
      ZO_DATA_DIR: /data
      ZO_ROOT_USER_EMAIL: ${ADMIN_EMAIL}
      ZO_ROOT_USER_PASSWORD: ${ADMIN_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:5080"
      - "${DOCKER_BRIDGE}:${PORT}:5080"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
