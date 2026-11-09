<?php

return [
    'name' => 'Langfuse',
    'category' => 'Infrastructure',
    'description' => 'Tracing, evals and prompt management for LLM apps, with Postgres, ClickHouse, Redis and MinIO.',
    'icon' => 'chart',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/public/health',
    'notes' => 'Sign up on first visit to make your account, then create a project to get API keys. It runs six containers, so give it at least 4 GB of RAM.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'AUTH_SECRET', 'label' => 'Auth secret', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'SALT', 'label' => 'API key salt', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'INTERNAL_PASSWORD', 'label' => 'Internal services password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
x-langfuse-env: &langfuse-env
  DATABASE_URL: postgresql://langfuse:${INTERNAL_PASSWORD}@db:5432/langfuse
  SALT: ${SALT}
  TELEMETRY_ENABLED: "false"
  CLICKHOUSE_MIGRATION_URL: clickhouse://clickhouse:9000
  CLICKHOUSE_URL: http://clickhouse:8123
  CLICKHOUSE_USER: clickhouse
  CLICKHOUSE_PASSWORD: ${INTERNAL_PASSWORD}
  CLICKHOUSE_CLUSTER_ENABLED: "false"
  LANGFUSE_S3_EVENT_UPLOAD_BUCKET: langfuse
  LANGFUSE_S3_EVENT_UPLOAD_REGION: auto
  LANGFUSE_S3_EVENT_UPLOAD_ACCESS_KEY_ID: minio
  LANGFUSE_S3_EVENT_UPLOAD_SECRET_ACCESS_KEY: ${INTERNAL_PASSWORD}
  LANGFUSE_S3_EVENT_UPLOAD_ENDPOINT: http://minio:9000
  LANGFUSE_S3_EVENT_UPLOAD_FORCE_PATH_STYLE: "true"
  LANGFUSE_S3_EVENT_UPLOAD_PREFIX: events/
  REDIS_HOST: redis
  REDIS_PORT: "6379"
  REDIS_AUTH: ${INTERNAL_PASSWORD}
services:
  web:
    image: langfuse/langfuse:3
    restart: unless-stopped
    depends_on: [db, clickhouse, redis, minio]
    environment:
      <<: *langfuse-env
      NEXTAUTH_URL: ${APP_URL}
      NEXTAUTH_SECRET: ${AUTH_SECRET}
    ports:
      - "127.0.0.1:${PORT}:3000"
  worker:
    image: langfuse/langfuse-worker:3
    restart: unless-stopped
    depends_on: [db, clickhouse, redis, minio]
    environment:
      <<: *langfuse-env
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: langfuse
      POSTGRES_USER: langfuse
      POSTGRES_PASSWORD: ${INTERNAL_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
  clickhouse:
    image: clickhouse/clickhouse-server:latest
    restart: unless-stopped
    user: "101:101"
    environment:
      CLICKHOUSE_DB: default
      CLICKHOUSE_USER: clickhouse
      CLICKHOUSE_PASSWORD: ${INTERNAL_PASSWORD}
    volumes:
      - clickhouse:/var/lib/clickhouse
      - clickhouse-logs:/var/log/clickhouse-server
  redis:
    image: redis:7
    restart: unless-stopped
    command: ["redis-server", "--requirepass", "${INTERNAL_PASSWORD}", "--maxmemory-policy", "noeviction"]
    volumes:
      - redis:/data
  minio:
    image: pgsty/minio:latest
    restart: unless-stopped
    entrypoint: sh
    command: ["-c", "mkdir -p /data/langfuse && minio server --address :9000 /data"]
    environment:
      MINIO_ROOT_USER: minio
      MINIO_ROOT_PASSWORD: ${INTERNAL_PASSWORD}
    volumes:
      - minio:/data
volumes:
  db:
  clickhouse:
  clickhouse-logs:
  redis:
  minio:
YAML,
    ],
];
