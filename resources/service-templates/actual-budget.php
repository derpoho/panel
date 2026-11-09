<?php

return [
    'name' => 'Actual Budget',
    'category' => 'Self-hosted',
    'description' => 'Local-first personal budgeting, synced across your devices.',
    'icon' => 'actualbudget',
    'kind' => 'compose',
    'port' => 5006,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Set the server password in the browser. Add a domain: Actual needs HTTPS outside localhost.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  actual:
    image: actualbudget/actual-server:latest
    restart: unless-stopped
    environment:
      # Listen on IPv4: Actual's default "::" fails on hosts with IPv6 off.
      ACTUAL_HOSTNAME: 0.0.0.0
    ports:
      - "127.0.0.1:${PORT}:5006"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
