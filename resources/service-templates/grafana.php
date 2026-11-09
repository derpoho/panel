<?php

return [
    'name' => 'Grafana',
    'category' => 'Self-hosted',
    'description' => 'Dashboards and alerting for your metrics.',
    'icon' => 'grafana',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/health',
    'notes' => 'Sign in as admin with the password shown after deploy.',
    'env' => [
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  grafana:
    image: grafana/grafana:11.3.0
    restart: unless-stopped
    environment:
      GF_SECURITY_ADMIN_USER: admin
      GF_SECURITY_ADMIN_PASSWORD: ${ADMIN_PASSWORD}
      GF_SERVER_ROOT_URL: ${APP_URL}
    ports:
      - "127.0.0.1:${PORT}:3000"
    volumes:
      - data:/var/lib/grafana
volumes:
  data:
YAML,
    ],
];
