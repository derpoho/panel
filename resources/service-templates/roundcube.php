<?php

return [
    'name' => 'Roundcube',
    'category' => 'Self-hosted',
    'description' => 'Webmail for any IMAP mailbox.',
    'icon' => 'roundcube',
    'kind' => 'compose',
    'port' => 80,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Sign in with your mailbox address and password. Point it at your mail server with the IMAP and SMTP fields.',
    'env' => [
        ['key' => 'IMAP_HOST', 'label' => 'IMAP server', 'default' => 'ssl://mail.example.com', 'hint' => 'Use ssl:// for port 993 or tls:// for 143.'],
        ['key' => 'IMAP_PORT', 'label' => 'IMAP port', 'default' => '993'],
        ['key' => 'SMTP_HOST', 'label' => 'SMTP server', 'default' => 'tls://mail.example.com'],
        ['key' => 'SMTP_PORT', 'label' => 'SMTP port', 'default' => '587'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  roundcube:
    image: roundcube/roundcubemail:latest
    restart: unless-stopped
    environment:
      ROUNDCUBEMAIL_DEFAULT_HOST: ${IMAP_HOST}
      ROUNDCUBEMAIL_DEFAULT_PORT: ${IMAP_PORT}
      ROUNDCUBEMAIL_SMTP_SERVER: ${SMTP_HOST}
      ROUNDCUBEMAIL_SMTP_PORT: ${SMTP_PORT}
      ROUNDCUBEMAIL_DB_TYPE: sqlite
      ROUNDCUBEMAIL_SKIN: elastic
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - html:/var/www/html
      - db:/var/roundcube/db
volumes:
  html:
  db:
YAML,
    ],
];
