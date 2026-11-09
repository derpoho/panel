<?php

return [
    'name' => 'Mumble',
    'category' => 'Game servers',
    'description' => 'Mumble server for low latency voice chat with your group.',
    'icon' => 'mumble',
    'kind' => 'compose',
    'port' => 64738,
    'web' => false,
    'port_editable' => false,
    'health' => null,
    'notes' => 'Uses TCP and UDP port 64738, open to the internet. Connect from the Mumble app with this machine’s IP. Log in as SuperUser with the SuperUser password to set up channels and admins.',
    'env' => [
        ['key' => 'MUMBLE_SUPERUSER_PASSWORD', 'label' => 'SuperUser password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'MUMBLE_CONFIG_SERVER_PASSWORD', 'label' => 'Join password', 'hint' => 'Leave empty for an open server.'],
        ['key' => 'MUMBLE_CONFIG_WELCOMETEXT', 'label' => 'Welcome message', 'default' => 'Welcome! Hosted with ServerOS.'],
        ['key' => 'MUMBLE_CONFIG_USERS', 'label' => 'Max users', 'default' => '100'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  mumble:
    image: mumblevoip/mumble-server:latest
    restart: unless-stopped
    environment:
      MUMBLE_SUPERUSER_PASSWORD: ${MUMBLE_SUPERUSER_PASSWORD}
      MUMBLE_CONFIG_SERVER_PASSWORD: ${MUMBLE_CONFIG_SERVER_PASSWORD}
      MUMBLE_CONFIG_WELCOMETEXT: ${MUMBLE_CONFIG_WELCOMETEXT}
      MUMBLE_CONFIG_USERS: ${MUMBLE_CONFIG_USERS}
    ports:
      - "64738:64738/tcp"
      - "64738:64738/udp"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
