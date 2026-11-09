<?php

return [
    'name' => 'Windmill',
    'category' => 'Infrastructure',
    'description' => 'Turn scripts into workflows, internal tools and cron jobs, with Postgres and a worker.',
    'icon' => 'workflow',
    'kind' => 'compose',
    'port' => 8000,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/version',
    'notes' => 'The first login is admin@windmill.dev / changeme. Change it straight away, then set the base URL in instance settings.',
    'env' => [
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  server:
    image: ghcr.io/windmill-labs/windmill:main
    restart: unless-stopped
    depends_on: [db]
    environment:
      DATABASE_URL: postgres://postgres:${DB_PASSWORD}@db:5432/windmill?sslmode=disable
      MODE: server
    ports:
      - "127.0.0.1:${PORT}:8000"
  worker:
    image: ghcr.io/windmill-labs/windmill:main
    restart: unless-stopped
    depends_on: [db]
    environment:
      DATABASE_URL: postgres://postgres:${DB_PASSWORD}@db:5432/windmill?sslmode=disable
      MODE: worker
      WORKER_GROUP: default
    volumes:
      - worker-cache:/tmp/windmill/cache
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: windmill
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  worker-cache:
  db:
YAML,
    ],
];
