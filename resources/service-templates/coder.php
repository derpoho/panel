<?php

return [
    'name' => 'Coder',
    'category' => 'Infrastructure',
    'description' => 'Remote dev environments from Terraform templates, on this machine\'s Docker.',
    'icon' => 'coder',
    'kind' => 'compose',
    'port' => 7080,
    'web' => true,
    'port_editable' => true,
    'health' => '/healthz',
    'notes' => 'Create the first admin on the first visit. Workspaces run as containers through the machine\'s Docker socket, so set the Docker group ID to the one on this machine (getent group docker). It runs with Postgres.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'DOCKER_GROUP', 'label' => 'Docker group ID', 'default' => '999', 'hint' => 'getent group docker | cut -d: -f3'],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  coder:
    image: ghcr.io/coder/coder:latest
    restart: unless-stopped
    depends_on: [db]
    group_add:
      - "${DOCKER_GROUP}"
    environment:
      CODER_PG_CONNECTION_URL: postgresql://coder:${DB_PASSWORD}@db:5432/coder?sslmode=disable
      CODER_HTTP_ADDRESS: 0.0.0.0:7080
      CODER_ACCESS_URL: ${APP_URL}
    ports:
      - "127.0.0.1:${PORT}:7080"
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: coder
      POSTGRES_USER: coder
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  db:
YAML,
    ],
];
