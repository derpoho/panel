<?php

return [
    'name' => 'LibreChat',
    'category' => 'Self-hosted',
    'description' => 'ChatGPT-style chat for OpenAI, Anthropic and other models, with MongoDB and Meilisearch.',
    'icon' => 'bot',
    'kind' => 'compose',
    'port' => 3080,
    'web' => true,
    'port_editable' => true,
    'health' => '/health',
    'notes' => 'Register the first account in the browser; it becomes the admin. Each person adds their own API keys unless you set them here.',
    'env' => [
        ['key' => 'PUBLIC_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'OPENAI_API_KEY', 'label' => 'OpenAI API key', 'default' => 'user_provided', 'secret' => true, 'hint' => 'Leave as user_provided to let each person enter their own.'],
        ['key' => 'ANTHROPIC_API_KEY', 'label' => 'Anthropic API key', 'default' => 'user_provided', 'secret' => true],
        ['key' => 'CREDS_SEED', 'label' => 'Encryption seed', 'default' => '{random:48}', 'secret' => true],
        ['key' => 'JWT_SECRET', 'label' => 'JWT secret', 'default' => '{random:48}', 'secret' => true],
        ['key' => 'JWT_REFRESH_SECRET', 'label' => 'JWT refresh secret', 'default' => '{random:48}', 'secret' => true],
        ['key' => 'MEILI_MASTER_KEY', 'label' => 'Meilisearch key', 'default' => '{random:32}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  librechat:
    image: ghcr.io/danny-avila/librechat:latest
    restart: unless-stopped
    depends_on: [mongodb, meilisearch]
    command: [sh, -c, "export CREDS_KEY=$$(printf %s \"$$CREDS_SEED\" | sha256sum | cut -c1-64) CREDS_IV=$$(printf %s \"$$CREDS_SEED\" | md5sum | cut -c1-32) && exec npm run backend"]
    environment:
      HOST: 0.0.0.0
      PORT: "3080"
      DOMAIN_CLIENT: ${PUBLIC_URL}
      DOMAIN_SERVER: ${PUBLIC_URL}
      MONGO_URI: mongodb://mongodb:27017/LibreChat
      SEARCH: "true"
      MEILI_HOST: http://meilisearch:7700
      MEILI_MASTER_KEY: ${MEILI_MASTER_KEY}
      CREDS_SEED: ${CREDS_SEED}
      JWT_SECRET: ${JWT_SECRET}
      JWT_REFRESH_SECRET: ${JWT_REFRESH_SECRET}
      OPENAI_API_KEY: ${OPENAI_API_KEY}
      ANTHROPIC_API_KEY: ${ANTHROPIC_API_KEY}
      ALLOW_REGISTRATION: "true"
    ports:
      - "127.0.0.1:${PORT}:3080"
    volumes:
      - uploads:/app/uploads
      - images:/app/client/public/images
      - logs:/app/logs
  mongodb:
    image: mongo:8
    restart: unless-stopped
    command: mongod --noauth
    volumes:
      - mongo:/data/db
  meilisearch:
    image: getmeili/meilisearch:v1.12
    restart: unless-stopped
    environment:
      MEILI_NO_ANALYTICS: "true"
      MEILI_MASTER_KEY: ${MEILI_MASTER_KEY}
    volumes:
      - meili:/meili_data
volumes:
  uploads:
  images:
  logs:
  mongo:
  meili:
YAML,
    ],
];
