<?php

return [
    'name' => 'LiteLLM',
    'category' => 'Infrastructure',
    'description' => 'One OpenAI-style API in front of many model providers, with keys and spend tracking.',
    'icon' => 'bot',
    'kind' => 'compose',
    'port' => 4000,
    'web' => true,
    'port_editable' => true,
    'health' => '/health/liveliness',
    'notes' => 'The admin UI is at /ui. Log in as admin with the UI password. The master API key is sk- followed by the master key below. Add models and provider keys in the UI.',
    'env' => [
        ['key' => 'UI_PASSWORD', 'label' => 'UI password', 'default' => '{random:20}', 'secret' => true],
        ['key' => 'MASTER_KEY', 'label' => 'Master key', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'SALT_KEY', 'label' => 'Salt key', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  litellm:
    image: ghcr.io/berriai/litellm:main-stable
    restart: unless-stopped
    depends_on: [db]
    environment:
      DATABASE_URL: postgresql://litellm:${DB_PASSWORD}@db:5432/litellm
      LITELLM_MASTER_KEY: sk-${MASTER_KEY}
      LITELLM_SALT_KEY: sk-${SALT_KEY}
      UI_USERNAME: admin
      UI_PASSWORD: ${UI_PASSWORD}
      STORE_MODEL_IN_DB: "True"
    ports:
      - "127.0.0.1:${PORT}:4000"
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: litellm
      POSTGRES_USER: litellm
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  db:
YAML,
    ],
];
