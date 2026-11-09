<?php

return [
    'name' => 'OpenVSCode Server',
    'category' => 'Infrastructure',
    'description' => 'VS Code in the browser, running on your server.',
    'icon' => 'code',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Open YOUR-URL/?tkn=TOKEN with the connection token from your settings. Your settings and files live in the config volume.',
    'env' => [
        ['key' => 'TOKEN', 'label' => 'Connection token', 'default' => '{random:32}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  openvscode-server:
    image: lscr.io/linuxserver/openvscode-server:latest
    restart: unless-stopped
    environment:
      PUID: 1000
      PGID: 1000
      TZ: Etc/UTC
      CONNECTION_TOKEN: ${TOKEN}
    ports:
      - "127.0.0.1:${PORT}:3000"
    volumes:
      - config:/config
volumes:
  config:
YAML,
    ],
];
