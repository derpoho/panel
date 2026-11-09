<?php

return [
    'name' => 'ZincSearch',
    'category' => 'Infrastructure',
    'description' => 'Lightweight search engine with an Elasticsearch-style API and a web UI.',
    'icon' => 'zincsearch',
    'kind' => 'compose',
    'port' => 4080,
    'web' => true,
    'port_editable' => true,
    'health' => '/healthz',
    'notes' => 'Sign in with the admin user and password from your settings. Apps on this machine reach the API at http://{bridge}:PORT/api.',
    'env' => [
        ['key' => 'ADMIN_USER', 'label' => 'Admin user', 'default' => 'admin'],
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:20}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  zincsearch:
    image: public.ecr.aws/zinclabs/zincsearch:latest
    restart: unless-stopped
    user: "0"
    environment:
      ZINC_DATA_PATH: /data
      ZINC_FIRST_ADMIN_USER: ${ADMIN_USER}
      ZINC_FIRST_ADMIN_PASSWORD: ${ADMIN_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:4080"
      - "${DOCKER_BRIDGE}:${PORT}:4080"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
