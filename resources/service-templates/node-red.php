<?php

return [
    'name' => 'Node-RED',
    'category' => 'Infrastructure',
    'description' => 'Wire together devices, APIs and services in a browser flow editor.',
    'icon' => 'nodered',
    'kind' => 'compose',
    'port' => 1880,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'The editor has no login by default. Turn on adminAuth in settings.js in the data volume before you put it on a domain.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  node-red:
    image: nodered/node-red:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:1880"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
