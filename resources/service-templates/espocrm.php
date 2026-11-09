<?php

return [
    'name' => 'EspoCRM',
    'category' => 'Self-hosted',
    'description' => 'CRM for leads, contacts, deals and email, with MariaDB.',
    'icon' => 'table',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Sign in as admin with the password you set here. The first start installs the database and takes a minute.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:20}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  espocrm:
    image: espocrm/espocrm:latest
    restart: unless-stopped
    depends_on:
      db:
        condition: service_healthy
    environment:
      ESPOCRM_DATABASE_HOST: db
      ESPOCRM_DATABASE_NAME: espocrm
      ESPOCRM_DATABASE_USER: espocrm
      ESPOCRM_DATABASE_PASSWORD: ${DB_PASSWORD}
      ESPOCRM_ADMIN_USERNAME: admin
      ESPOCRM_ADMIN_PASSWORD: ${ADMIN_PASSWORD}
      ESPOCRM_SITE_URL: ${APP_URL}
    healthcheck:
      test: ["CMD", "bin/command", "app-check"]
      start_period: 20s
      interval: 60s
      timeout: 20s
      retries: 3
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - data:/var/www/html/data
      - custom:/var/www/html/custom
      - custom-client:/var/www/html/client/custom
  daemon:
    image: espocrm/espocrm:latest
    restart: unless-stopped
    entrypoint: docker-daemon.sh
    depends_on:
      espocrm:
        condition: service_healthy
    volumes:
      - data:/var/www/html/data
      - custom:/var/www/html/custom
      - custom-client:/var/www/html/client/custom
  db:
    image: mariadb:11
    restart: unless-stopped
    environment:
      MARIADB_DATABASE: espocrm
      MARIADB_USER: espocrm
      MARIADB_PASSWORD: ${DB_PASSWORD}
      MARIADB_ROOT_PASSWORD: ${DB_PASSWORD}
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 20s
      start_period: 10s
      timeout: 10s
      retries: 3
    volumes:
      - db:/var/lib/mysql
volumes:
  data:
  custom:
  custom-client:
  db:
YAML,
    ],
];
