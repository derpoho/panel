<?php

return [
    'name' => 'Huginn',
    'category' => 'Infrastructure',
    'description' => 'Agents that watch the web and act for you, like a self-hosted IFTTT, with Postgres.',
    'icon' => 'bot',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/users/sign_in',
    'notes' => 'The first login is admin / password. Change it straight away.',
    'env' => [
        ['key' => 'APP_SECRET', 'label' => 'App secret', 'default' => '{random:64}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  huginn:
    image: ghcr.io/huginn/huginn:latest
    restart: unless-stopped
    depends_on: [db]
    environment:
      DATABASE_ADAPTER: postgresql
      DATABASE_HOST: db
      DATABASE_PORT: "5432"
      DATABASE_NAME: huginn
      DATABASE_USERNAME: huginn
      DATABASE_PASSWORD: ${DB_PASSWORD}
      APP_SECRET_TOKEN: ${APP_SECRET}
    ports:
      - "127.0.0.1:${PORT}:3000"
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: huginn
      POSTGRES_USER: huginn
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  db:
YAML,
    ],
];
