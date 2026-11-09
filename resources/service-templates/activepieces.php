<?php

return [
    'name' => 'Activepieces',
    'category' => 'Infrastructure',
    'description' => 'No-code automations between your apps, like Zapier, with Postgres and Redis.',
    'icon' => 'workflow',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/v1/flags',
    'notes' => 'Open it straight after deploying to create the admin account.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'ENCRYPTION_KEY', 'label' => 'Encryption key', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'JWT_SECRET', 'label' => 'JWT secret', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  activepieces:
    image: ghcr.io/activepieces/activepieces:latest
    restart: unless-stopped
    depends_on: [db, redis]
    environment:
      AP_ENGINE_EXECUTABLE_PATH: dist/packages/engine/main.js
      AP_ENVIRONMENT: prod
      AP_FRONTEND_URL: ${APP_URL}
      AP_ENCRYPTION_KEY: ${ENCRYPTION_KEY}
      AP_JWT_SECRET: ${JWT_SECRET}
      AP_EXECUTION_MODE: UNSANDBOXED
      AP_DB_TYPE: POSTGRES
      AP_POSTGRES_HOST: db
      AP_POSTGRES_PORT: "5432"
      AP_POSTGRES_DATABASE: activepieces
      AP_POSTGRES_USERNAME: activepieces
      AP_POSTGRES_PASSWORD: ${DB_PASSWORD}
      AP_REDIS_HOST: redis
      AP_REDIS_PORT: "6379"
      AP_TELEMETRY_ENABLED: "false"
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - cache:/usr/src/app/cache
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: activepieces
      POSTGRES_USER: activepieces
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
  redis:
    image: redis:7
    restart: unless-stopped
    volumes:
      - redis:/data
volumes:
  cache:
  db:
  redis:
YAML,
    ],
];
