<?php

return [
    'name' => 'SFTPGo',
    'category' => 'Self-hosted',
    'description' => 'SFTP, FTP and WebDAV server with a web admin and per-user folders.',
    'icon' => 'folder',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/healthz',
    'notes' => 'Open /web/admin in the browser to create the admin account, then add users. SFTP listens on TCP port 2022, open to the internet.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  sftpgo:
    image: drakkan/sftpgo:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:8080"
      - "2022:2022"
    volumes:
      - data:/srv/sftpgo
      - home:/var/lib/sftpgo
volumes:
  data:
  home:
YAML,
    ],
];
