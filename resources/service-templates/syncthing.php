<?php

return [
    'name' => 'Syncthing',
    'category' => 'Self-hosted',
    'description' => 'Keep folders in sync between your computers, phone and this server, from the official image.',
    'icon' => 'syncthing',
    'kind' => 'compose',
    'port' => 8384,
    'web' => true,
    'port_editable' => true,
    'health' => '/rest/noauth/health',
    'notes' => 'Sign in to the web page with the user and password below, then add your devices by their device IDs. Devices sync over TCP and UDP port 22000, open to the internet. Synced folders live under /var/syncthing on this service.',
    'env' => [
        ['key' => 'GUI_USER', 'label' => 'Web user', 'default' => 'admin'],
        ['key' => 'GUI_PASSWORD', 'label' => 'Web password', 'default' => '{random:24}', 'secret' => true, 'hint' => 'Set again on every start, so change it here and redeploy.'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  syncthing:
    image: syncthing/syncthing:latest
    restart: unless-stopped
    hostname: syncthing
    environment:
      PUID: "1000"
      PGID: "1000"
      GUI_USER: ${GUI_USER}
      GUI_PASSWORD: ${GUI_PASSWORD}
    entrypoint: ["/bin/sh", "-c"]
    command:
      - /bin/entrypoint.sh /bin/syncthing generate --gui-user="$$GUI_USER" --gui-password="$$GUI_PASSWORD" && exec /bin/entrypoint.sh /bin/syncthing
    ports:
      - "127.0.0.1:${PORT}:8384"
      - "22000:22000/tcp"
      - "22000:22000/udp"
    volumes:
      - data:/var/syncthing
volumes:
  data:
YAML,
    ],
];
