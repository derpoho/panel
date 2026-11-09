<?php

return [
    'name' => 'Gogs',
    'category' => 'Infrastructure',
    'description' => 'Small, painless self-hosted Git service written in Go.',
    'icon' => 'code',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/healthcheck',
    'notes' => 'Finish the install page on the first visit: pick SQLite3 for the database, set the domain and application URL to this service\'s address and the SSH port to the one in your settings. The first account you register becomes the admin. Git over SSH is open to the internet on the SSH port.',
    'env' => [
        ['key' => 'SSH_PORT', 'label' => 'SSH port', 'default' => '2222'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  gogs:
    image: gogs/gogs:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:3000"
      - "${SSH_PORT}:22"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
