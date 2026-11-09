<?php

return [
    'name' => 'Opengist',
    'category' => 'Self-hosted',
    'description' => 'Your own GitHub Gist, with snippets you can clone and push with Git.',
    'icon' => 'code',
    'kind' => 'compose',
    'port' => 6157,
    'web' => true,
    'port_editable' => true,
    'health' => '/healthcheck',
    'notes' => 'The first account you register becomes the admin. Git works over HTTP on the same address.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  opengist:
    image: ghcr.io/thomiceli/opengist:1
    restart: unless-stopped
    environment:
      OG_EXTERNAL_URL: ${APP_URL}
      OG_SSH_GIT_ENABLED: "false"
    ports:
      - "127.0.0.1:${PORT}:6157"
    volumes:
      - data:/opengist
volumes:
  data:
YAML,
    ],
];
