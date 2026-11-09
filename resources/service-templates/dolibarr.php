<?php

return [
    'name' => 'Dolibarr',
    'category' => 'Self-hosted',
    'description' => 'ERP and CRM for small businesses: customers, quotes, invoices, stock and more.',
    'icon' => 'dolibarr',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Sign in as admin with the password you set here. The first start installs the database and takes a minute.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:20}', 'secret' => true],
        ['key' => 'COMPANY_NAME', 'label' => 'Company name', 'default' => 'My Company'],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  dolibarr:
    image: dolibarr/dolibarr:latest
    restart: unless-stopped
    depends_on: [db]
    environment:
      DOLI_DB_HOST: db
      DOLI_DB_NAME: dolibarr
      DOLI_DB_USER: dolibarr
      DOLI_DB_PASSWORD: ${DB_PASSWORD}
      DOLI_URL_ROOT: ${APP_URL}
      DOLI_ADMIN_LOGIN: admin
      DOLI_ADMIN_PASSWORD: ${ADMIN_PASSWORD}
      DOLI_COMPANY_NAME: ${COMPANY_NAME}
      DOLI_INIT_DEMO: 0
      PHP_INI_DATE_TIMEZONE: ${TIMEZONE}
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - documents:/var/www/documents
      - custom:/var/www/html/custom
  db:
    image: mariadb:11
    restart: unless-stopped
    environment:
      MARIADB_DATABASE: dolibarr
      MARIADB_USER: dolibarr
      MARIADB_PASSWORD: ${DB_PASSWORD}
      MARIADB_ROOT_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/mysql
volumes:
  documents:
  custom:
  db:
YAML,
    ],
];
