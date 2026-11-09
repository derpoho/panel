<?php

return [
    'name' => 'KitchenOwl',
    'category' => 'Self-hosted',
    'description' => 'A shared grocery list, recipe book and meal planner for your household.',
    'icon' => 'book',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Open it in the browser to create the first account, which becomes the admin.',
    'env' => [
        ['key' => 'JWT_SECRET_KEY', 'label' => 'JWT secret', 'default' => '{random:48}', 'secret' => true],
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  kitchenowl:
    image: tombursch/kitchenowl:latest
    restart: unless-stopped
    environment:
      JWT_SECRET_KEY: ${JWT_SECRET_KEY}
      FRONT_URL: ${APP_URL}
    ports:
      - "127.0.0.1:${PORT}:8080"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
