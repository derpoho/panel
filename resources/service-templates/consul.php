<?php

return [
    'name' => 'Consul',
    'category' => 'Infrastructure',
    'description' => 'Service discovery and a key/value store, as a single server node.',
    'icon' => 'consul',
    'kind' => 'compose',
    'port' => 8500,
    'web' => true,
    'port_editable' => true,
    'health' => '/v1/status/leader',
    'notes' => 'The UI is at /ui. ACLs are off, so anyone who can reach the port can change things: keep it local or put it behind a login.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  consul:
    image: hashicorp/consul:latest
    restart: unless-stopped
    command: ["agent", "-server", "-bootstrap-expect=1", "-ui", "-client=0.0.0.0", "-data-dir=/consul/data"]
    ports:
      - "127.0.0.1:${PORT}:8500"
    volumes:
      - data:/consul/data
volumes:
  data:
YAML,
    ],
];
