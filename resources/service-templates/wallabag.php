<?php

return [
    'name' => 'wallabag',
    'category' => 'Self-hosted',
    'description' => 'Save articles to read later, without the clutter.',
    'icon' => 'wallabag',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'The first login is wallabag / wallabag. Change it straight away.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'APP_SECRET', 'label' => 'App secret', 'default' => '{random:32}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  wallabag:
    image: wallabag/wallabag:latest
    restart: unless-stopped
    depends_on: [redis]
    environment:
      SYMFONY__ENV__DOMAIN_NAME: ${APP_URL}
      SYMFONY__ENV__SECRET: ${APP_SECRET}
      SYMFONY__ENV__SERVER_NAME: wallabag
      SYMFONY__ENV__REDIS_HOST: redis
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - data:/var/www/wallabag/data
      - images:/var/www/wallabag/web/assets/images
  redis:
    image: redis:7-alpine
    restart: unless-stopped
volumes:
  data:
  images:
YAML,
    ],
];
