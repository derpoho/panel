<?php

return [
    'name' => 'Velocity',
    'category' => 'Game servers',
    'description' => 'Velocity proxy that links several Minecraft servers behind one address.',
    'icon' => 'minecraft',
    'kind' => 'compose',
    'port' => 25565,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Players join on this machine’s IP and the port you chose. The port is open to the internet. List your backend servers in velocity.toml on the data volume, and copy forwarding.secret to each Paper server’s config so they accept players from the proxy.',
    'env' => [
        ['key' => 'MEMORY', 'label' => 'Memory', 'default' => '512m'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  velocity:
    image: itzg/mc-proxy:latest
    restart: unless-stopped
    tty: true
    stdin_open: true
    environment:
      TYPE: VELOCITY
      MEMORY: ${MEMORY}
    ports:
      - "${PORT}:25577"
    volumes:
      - data:/server
volumes:
  data:
YAML,
    ],
];
