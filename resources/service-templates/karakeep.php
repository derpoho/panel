<?php

return [
    'name' => 'Karakeep',
    'category' => 'Self-hosted',
    'description' => 'Bookmark everything, with full-text search and page archiving.',
    'icon' => 'karakeep',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/health',
    'notes' => 'Sign up in the browser. The first account becomes the admin.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'NEXTAUTH_SECRET', 'label' => 'Auth secret', 'default' => '{random:32}', 'secret' => true],
        ['key' => 'MEILI_MASTER_KEY', 'label' => 'Search key', 'default' => '{random:32}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  karakeep:
    image: ghcr.io/karakeep-app/karakeep:release
    restart: unless-stopped
    depends_on: [meilisearch, chrome]
    environment:
      NEXTAUTH_URL: ${APP_URL}
      NEXTAUTH_SECRET: ${NEXTAUTH_SECRET}
      MEILI_MASTER_KEY: ${MEILI_MASTER_KEY}
      MEILI_ADDR: http://meilisearch:7700
      BROWSER_WEB_URL: http://chrome:9222
      DATA_DIR: /data
    ports:
      - "127.0.0.1:${PORT}:3000"
    volumes:
      - data:/data
  chrome:
    image: gcr.io/zenika-hub/alpine-chrome:124
    restart: unless-stopped
    command:
      - --no-sandbox
      - --disable-gpu
      - --disable-dev-shm-usage
      - --remote-debugging-address=0.0.0.0
      - --remote-debugging-port=9222
      - --hide-scrollbars
  meilisearch:
    image: getmeili/meilisearch:v1.13
    restart: unless-stopped
    environment:
      MEILI_MASTER_KEY: ${MEILI_MASTER_KEY}
      MEILI_NO_ANALYTICS: "true"
    volumes:
      - meili:/meili_data
volumes:
  data:
  meili:
YAML,
    ],
];
