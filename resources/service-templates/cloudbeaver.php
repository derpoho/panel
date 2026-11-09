<?php

return [
    'name' => 'CloudBeaver',
    'category' => 'Infrastructure',
    'description' => 'DBeaver in the browser, a SQL client for most databases.',
    'icon' => 'dbeaver',
    'kind' => 'compose',
    'port' => 8978,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'The first visit runs a setup wizard where you create the admin account, so open it straight away. For databases on this machine, connect to {bridge} and their port.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  cloudbeaver:
    image: dbeaver/cloudbeaver:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:8978"
    volumes:
      - workspace:/opt/cloudbeaver/workspace
volumes:
  workspace:
YAML,
    ],
];
