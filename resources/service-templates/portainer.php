<?php

return [
    'name' => 'Portainer',
    'category' => 'Infrastructure',
    'description' => 'Web UI for the containers, images and volumes on this machine.',
    'icon' => 'portainer',
    'kind' => 'compose',
    'port' => 9000,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/system/status',
    'notes' => 'Create the admin account within 5 minutes of deploying, or Portainer locks setup until it restarts. It controls Docker on this machine, so treat it like root access.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  portainer:
    image: portainer/portainer-ce:lts
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:9000"
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock
      - data:/data
volumes:
  data:
YAML,
    ],
];
