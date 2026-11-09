<?php

return [
    'name' => 'Zipline',
    'category' => 'Self-hosted',
    'description' => 'File, screenshot and link sharing that works with ShareX, with Postgres.',
    'icon' => 'image',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/healthcheck',
    'notes' => 'Open it in the browser to create the first admin account.',
    'env' => [
        ['key' => 'CORE_SECRET', 'label' => 'Secret', 'default' => '{random:48}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  zipline:
    image: ghcr.io/diced/zipline:latest
    restart: unless-stopped
    depends_on: [db]
    environment:
      CORE_SECRET: ${CORE_SECRET}
      CORE_HOSTNAME: 0.0.0.0
      CORE_PORT: "3000"
      DATABASE_URL: postgres://zipline:${DB_PASSWORD}@db:5432/zipline
    ports:
      - "127.0.0.1:${PORT}:3000"
    volumes:
      - uploads:/zipline/uploads
      - public:/zipline/public
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: zipline
      POSTGRES_USER: zipline
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  uploads:
  public:
  db:
YAML,
    ],
];
