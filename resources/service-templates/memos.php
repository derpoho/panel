<?php

return [
    'name' => 'Memos',
    'category' => 'Self-hosted',
    'description' => 'Quick notes and a private microblog, in Markdown.',
    'icon' => 'memos',
    'kind' => 'compose',
    'port' => 5230,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'The first account you create becomes the admin.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  memos:
    image: neosmemo/memos:stable
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:5230"
    volumes:
      - data:/var/opt/memos
volumes:
  data:
YAML,
    ],
];
