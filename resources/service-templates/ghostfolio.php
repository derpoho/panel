<?php

return [
    'name' => 'Ghostfolio',
    'category' => 'Self-hosted',
    'description' => 'Track your stocks, ETFs and crypto in one portfolio, with Postgres and Redis.',
    'icon' => 'ghostfolio',
    'kind' => 'compose',
    'port' => 3333,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/v1/health',
    'notes' => 'Open it in the browser and click Get Started: the first account becomes the admin. Keep the security token it shows you, it is how you sign in.',
    'env' => [
        ['key' => 'ACCESS_TOKEN_SALT', 'label' => 'Access token salt', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'JWT_SECRET', 'label' => 'JWT secret', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'REDIS_PASSWORD', 'label' => 'Redis password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  ghostfolio:
    image: ghostfolio/ghostfolio:latest
    restart: unless-stopped
    depends_on: [db, redis]
    environment:
      ACCESS_TOKEN_SALT: ${ACCESS_TOKEN_SALT}
      JWT_SECRET_KEY: ${JWT_SECRET}
      DATABASE_URL: postgresql://ghostfolio:${DB_PASSWORD}@db:5432/ghostfolio?connect_timeout=300&sslmode=prefer
      REDIS_HOST: redis
      REDIS_PORT: 6379
      REDIS_PASSWORD: ${REDIS_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:3333"
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: ghostfolio
      POSTGRES_USER: ghostfolio
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
  redis:
    image: redis:7-alpine
    restart: unless-stopped
    command: ["redis-server", "--requirepass", "${REDIS_PASSWORD}"]
volumes:
  db:
YAML,
    ],
];
