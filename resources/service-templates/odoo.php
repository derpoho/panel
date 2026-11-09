<?php

return [
    'name' => 'Odoo',
    'category' => 'Self-hosted',
    'description' => 'Open source business apps for CRM, sales, invoicing, stock and more, with Postgres.',
    'icon' => 'odoo',
    'kind' => 'compose',
    'port' => 8069,
    'web' => true,
    'port_editable' => true,
    'health' => '/web/health',
    'notes' => 'Open it in the browser to create your first database and admin user. Save the master password it shows you, you need it to manage databases later.',
    'env' => [
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  odoo:
    image: odoo:19
    restart: unless-stopped
    depends_on: [db]
    command: ["--proxy-mode"]
    environment:
      HOST: db
      USER: odoo
      PASSWORD: ${DB_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:8069"
    volumes:
      - data:/var/lib/odoo
      - addons:/mnt/extra-addons
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: postgres
      POSTGRES_USER: odoo
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  data:
  addons:
  db:
YAML,
    ],
];
