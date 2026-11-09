<?php

return [
    'name' => 'OneDev',
    'category' => 'Infrastructure',
    'description' => 'Git server with CI/CD, kanban boards and package registries built in.',
    'icon' => 'code',
    'kind' => 'compose',
    'port' => 6610,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Set up the admin account on the first visit. CI jobs run as containers through the machine\'s Docker socket. Git over SSH is open to the internet on the SSH port.',
    'env' => [
        ['key' => 'SSH_PORT', 'label' => 'SSH port', 'default' => '6611'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  onedev:
    image: 1dev/server:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:6610"
      - "${SSH_PORT}:6611"
    volumes:
      - data:/opt/onedev
      - /var/run/docker.sock:/var/run/docker.sock
volumes:
  data:
YAML,
    ],
];
