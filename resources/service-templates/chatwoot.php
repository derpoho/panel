<?php

return [
    'name' => 'Chatwoot',
    'category' => 'Self-hosted',
    'description' => 'Customer support inbox for live chat, email and social messages, with Postgres and Redis.',
    'icon' => 'chatwoot',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/api',
    'notes' => 'Open it in the browser to create the admin account. The first start sets up the database and takes a few minutes. Add SMTP settings as extra environment to send email.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'SECRET_KEY_BASE', 'label' => 'Secret key', 'default' => '{random:64}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
x-chatwoot: &chatwoot
  image: chatwoot/chatwoot:latest
  restart: unless-stopped
  depends_on: [db, redis]
  environment:
    NODE_ENV: production
    RAILS_ENV: production
    INSTALLATION_ENV: docker
    RAILS_LOG_TO_STDOUT: "true"
    FRONTEND_URL: ${APP_URL}
    SECRET_KEY_BASE: ${SECRET_KEY_BASE}
    POSTGRES_HOST: db
    POSTGRES_PORT: 5432
    POSTGRES_DATABASE: chatwoot
    POSTGRES_USERNAME: chatwoot
    POSTGRES_PASSWORD: ${DB_PASSWORD}
    REDIS_URL: redis://redis:6379
    ACTIVE_STORAGE_SERVICE: local
  volumes:
    - storage:/app/storage
services:
  rails:
    <<: *chatwoot
    entrypoint: docker/entrypoints/rails.sh
    command: ["sh", "-c", "bundle exec rails db:chatwoot_prepare && exec bundle exec rails s -p 3000 -b 0.0.0.0"]
    ports:
      - "127.0.0.1:${PORT}:3000"
  sidekiq:
    <<: *chatwoot
    command: ["bundle", "exec", "sidekiq", "-C", "config/sidekiq.yml"]
  db:
    image: pgvector/pgvector:pg16
    restart: unless-stopped
    environment:
      POSTGRES_DB: chatwoot
      POSTGRES_USER: chatwoot
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
  redis:
    image: redis:7-alpine
    restart: unless-stopped
    volumes:
      - redis:/data
volumes:
  storage:
  db:
  redis:
YAML,
    ],
];
