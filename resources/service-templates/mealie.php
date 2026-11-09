<?php

return [
    'name' => 'Mealie',
    'category' => 'Self-hosted',
    'description' => 'Recipe manager and meal planner that imports recipes from any URL.',
    'icon' => 'mealie',
    'kind' => 'compose',
    'port' => 9925,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/app/about',
    'notes' => 'The first login is changeme@example.com / MyPassword. Change it straight away.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'ALLOW_SIGNUP', 'label' => 'Allow sign-ups', 'default' => 'false'],
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  mealie:
    image: ghcr.io/mealie-recipes/mealie:latest
    restart: unless-stopped
    environment:
      BASE_URL: ${APP_URL}
      ALLOW_SIGNUP: ${ALLOW_SIGNUP}
      TZ: ${TIMEZONE}
    ports:
      - "127.0.0.1:${PORT}:9000"
    volumes:
      - data:/app/data
volumes:
  data:
YAML,
    ],
];
