<?php

return [
    'name' => 'Gitea',
    'category' => 'Self-hosted',
    'description' => 'Lightweight Git hosting. SSH for clones on port 2222.',
    'icon' => 'gitea',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/healthz',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  gitea:
    image: gitea/gitea:1.22
    restart: unless-stopped
    environment:
      USER_UID: "1000"
      USER_GID: "1000"
      GITEA__server__ROOT_URL: ${APP_URL}
      GITEA__server__SSH_PORT: "2222"
    ports:
      - "127.0.0.1:${PORT}:3000"
      - "2222:22"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
