<?php

return [
    'name' => 'Flagsmith',
    'category' => 'Infrastructure',
    'description' => 'Feature flags and remote config for your apps, with Postgres.',
    'icon' => 'activity',
    'kind' => 'compose',
    'port' => 8000,
    'web' => true,
    'port_editable' => true,
    'health' => '/health',
    'notes' => 'Open it and sign up: the first account you create becomes the admin.',
    'env' => [
        ['key' => 'SECRET_KEY', 'label' => 'Secret key', 'default' => '{random:50}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  flagsmith:
    image: flagsmith/flagsmith:latest
    restart: unless-stopped
    depends_on: [db]
    environment:
      DATABASE_URL: postgresql://flagsmith:${DB_PASSWORD}@db:5432/flagsmith
      DJANGO_SECRET_KEY: ${SECRET_KEY}
      DJANGO_ALLOWED_HOSTS: "*"
      ENVIRONMENT: production
      USE_POSTGRES_FOR_ANALYTICS: "true"
      ENABLE_ADMIN_ACCESS_USER_PASS: "true"
    ports:
      - "127.0.0.1:${PORT}:8000"
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: flagsmith
      POSTGRES_USER: flagsmith
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  db:
YAML,
    ],
];
