<?php

return [
    'name' => 'Dozzle',
    'category' => 'Infrastructure',
    'description' => 'Live logs from every container on this machine, in the browser.',
    'icon' => 'dozzle',
    'kind' => 'compose',
    'port' => 8082,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'It has no login, and logs can hold secrets: keep it off a public domain.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  dozzle:
    image: amir20/dozzle:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:8080"
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock:ro
YAML,
    ],
];
