<?php

return [
    'name' => 'Stalwart',
    'category' => 'Self-hosted',
    'description' => 'All-in-one mail server with SMTP, IMAP, JMAP and spam filtering.',
    'icon' => 'mail',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/healthz/live',
    'notes' => 'The admin password is printed in the service logs on first start; sign in as admin. Mail ports 25 (SMTP), 465 and 587 (submission), 143 and 993 (IMAP) and 4190 (Sieve) are open to the internet. Many VPS hosts block port 25 until you ask, and you will need MX, SPF, DKIM and reverse DNS records set up.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  stalwart:
    image: stalwartlabs/stalwart:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:8080"
      - "25:25"
      - "465:465"
      - "587:587"
      - "143:143"
      - "993:993"
      - "4190:4190"
    volumes:
      - data:/opt/stalwart
volumes:
  data:
YAML,
    ],
];
