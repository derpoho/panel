<?php

return [
    'name' => 'GrowthBook',
    'category' => 'Infrastructure',
    'description' => 'Feature flags and A/B testing, with MongoDB.',
    'icon' => 'chart',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'The dashboard is on the main port and its API on the API port (3100), which your browser also calls. Give each its own domain and set the API URL to the second one. Sign up on first visit to create the admin.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'API_URL', 'label' => 'API URL', 'default' => 'http://localhost:3100', 'hint' => 'Public address of the API port'],
        ['key' => 'API_PORT', 'label' => 'API port', 'default' => '3100'],
        ['key' => 'JWT_SECRET', 'label' => 'JWT secret', 'default' => '{random:48}', 'secret' => true],
        ['key' => 'ENCRYPTION_KEY', 'label' => 'Encryption key', 'default' => '{random:48}', 'secret' => true],
        ['key' => 'MONGO_PASSWORD', 'label' => 'MongoDB password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  growthbook:
    image: growthbook/growthbook:latest
    restart: unless-stopped
    depends_on: [mongo]
    environment:
      MONGODB_URI: mongodb://root:${MONGO_PASSWORD}@mongo:27017/growthbook?authSource=admin
      APP_ORIGIN: ${APP_URL}
      API_HOST: ${API_URL}
      JWT_SECRET: ${JWT_SECRET}
      ENCRYPTION_KEY: ${ENCRYPTION_KEY}
      NODE_ENV: production
    ports:
      - "127.0.0.1:${PORT}:3000"
      - "127.0.0.1:${API_PORT}:3100"
    volumes:
      - uploads:/usr/local/src/app/packages/back-end/uploads
  mongo:
    image: mongo:8
    restart: unless-stopped
    environment:
      MONGO_INITDB_ROOT_USERNAME: root
      MONGO_INITDB_ROOT_PASSWORD: ${MONGO_PASSWORD}
    volumes:
      - mongo:/data/db
volumes:
  uploads:
  mongo:
YAML,
    ],
];
