<?php

return [
    'name' => 'SnappyMail',
    'category' => 'Self-hosted',
    'description' => 'Fast, simple webmail for your IMAP accounts.',
    'icon' => 'mail',
    'kind' => 'compose',
    'port' => 8888,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Set up domains at /?admin. Sign in as admin; the password is in _data_/_default_/admin_password.txt in the data volume.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  snappymail:
    image: djmaze/snappymail:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:8888"
    volumes:
      - data:/var/lib/snappymail
volumes:
  data:
YAML,
    ],
];
