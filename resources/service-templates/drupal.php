<?php

return [
    'name' => 'Drupal',
    'category' => 'Self-hosted',
    'description' => 'The open source CMS for content-heavy sites, with Postgres.',
    'icon' => 'drupal',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Open it in the browser to run the installer. Pick PostgreSQL, then use database drupal, user drupal, the database password from here, and host db under advanced options.',
    'env' => [
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  drupal:
    image: drupal:11-apache
    restart: unless-stopped
    depends_on: [db]
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - modules:/var/www/html/modules
      - profiles:/var/www/html/profiles
      - themes:/var/www/html/themes
      - sites:/var/www/html/sites
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: drupal
      POSTGRES_USER: drupal
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  modules:
  profiles:
  themes:
  sites:
  db:
YAML,
    ],
];
