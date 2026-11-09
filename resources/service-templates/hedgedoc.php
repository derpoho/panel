<?php

return [
    'name' => 'HedgeDoc',
    'category' => 'Self-hosted',
    'description' => 'Write markdown together in real time, with Postgres.',
    'icon' => 'hedgedoc',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/status',
    'notes' => 'Register an account in the browser to start writing.',
    'env' => [
        ['key' => 'DOMAIN', 'label' => 'Domain', 'default' => 'localhost', 'hint' => 'The domain you will open it on, like notes.example.com.'],
        ['key' => 'SESSION_SECRET', 'label' => 'Session secret', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  hedgedoc:
    image: quay.io/hedgedoc/hedgedoc:latest
    restart: unless-stopped
    depends_on: [db]
    environment:
      CMD_DB_URL: postgres://hedgedoc:${DB_PASSWORD}@db:5432/hedgedoc
      CMD_DOMAIN: ${DOMAIN}
      CMD_PROTOCOL_USESSL: "true"
      CMD_URL_ADDPORT: "false"
      CMD_SESSION_SECRET: ${SESSION_SECRET}
    ports:
      - "127.0.0.1:${PORT}:3000"
    volumes:
      - uploads:/hedgedoc/public/uploads
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: hedgedoc
      POSTGRES_USER: hedgedoc
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  uploads:
  db:
YAML,
    ],
];
