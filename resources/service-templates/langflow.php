<?php

return [
    'name' => 'Langflow',
    'category' => 'Infrastructure',
    'description' => 'Visual builder for AI agents and RAG flows, with Postgres.',
    'icon' => 'langflow',
    'kind' => 'compose',
    'port' => 7860,
    'web' => true,
    'port_editable' => true,
    'health' => '/health',
    'notes' => 'Log in as admin with the password below.',
    'env' => [
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:20}', 'secret' => true],
        ['key' => 'SECRET_KEY', 'label' => 'Secret key', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  langflow:
    image: langflowai/langflow:latest
    restart: unless-stopped
    depends_on: [db]
    environment:
      LANGFLOW_DATABASE_URL: postgresql://langflow:${DB_PASSWORD}@db:5432/langflow
      LANGFLOW_CONFIG_DIR: /app/langflow
      LANGFLOW_AUTO_LOGIN: "False"
      LANGFLOW_SUPERUSER: admin
      LANGFLOW_SUPERUSER_PASSWORD: ${ADMIN_PASSWORD}
      LANGFLOW_SECRET_KEY: ${SECRET_KEY}
      LANGFLOW_NEW_USER_IS_ACTIVE: "False"
      DO_NOT_TRACK: "true"
    ports:
      - "127.0.0.1:${PORT}:7860"
    volumes:
      - data:/app/langflow
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: langflow
      POSTGRES_USER: langflow
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  data:
  db:
YAML,
    ],
];
