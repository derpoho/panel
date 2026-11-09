<?php

return [
    'name' => 'Kimai',
    'category' => 'Self-hosted',
    'description' => 'Time tracking for freelancers and teams, with timesheets, invoices and reports.',
    'icon' => 'calendar',
    'kind' => 'compose',
    'port' => 8001,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Sign in with the admin email and password you set here.',
    'env' => [
        ['key' => 'ADMIN_EMAIL', 'label' => 'Admin email', 'default' => 'admin@example.com'],
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:20}', 'secret' => true],
        ['key' => 'APP_SECRET', 'label' => 'App secret', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  kimai:
    image: kimai/kimai2:apache
    restart: unless-stopped
    depends_on:
      db:
        condition: service_healthy
    environment:
      APP_SECRET: ${APP_SECRET}
      TRUSTED_HOSTS: ".*"
      ADMINMAIL: ${ADMIN_EMAIL}
      ADMINPASS: ${ADMIN_PASSWORD}
      DATABASE_URL: mysql://kimai:${DB_PASSWORD}@db/kimai?charset=utf8mb4&serverVersion=8.4.0
    ports:
      - "127.0.0.1:${PORT}:8001"
    volumes:
      - data:/opt/kimai/var/data
      - plugins:/opt/kimai/var/plugins
  db:
    image: mysql:8.4
    restart: unless-stopped
    environment:
      MYSQL_DATABASE: kimai
      MYSQL_USER: kimai
      MYSQL_PASSWORD: ${DB_PASSWORD}
      MYSQL_ROOT_PASSWORD: ${DB_PASSWORD}
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "127.0.0.1", "--silent"]
      interval: 10s
      timeout: 5s
      retries: 12
      start_period: 30s
    volumes:
      - db:/var/lib/mysql
volumes:
  data:
  plugins:
  db:
YAML,
    ],
];
