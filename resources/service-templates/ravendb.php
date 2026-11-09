<?php

return [
    'name' => 'RavenDB',
    'category' => 'Databases',
    'description' => 'Document database with a built-in web studio, run without certificates.',
    'icon' => 'database',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Open the studio to create databases and register a free community license. It runs without a certificate or login, so it only listens locally. Clients connect at http://127.0.0.1:PORT; apps you deploy on this machine connect through {bridge} instead.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  ravendb:
    image: ravendb/ravendb:latest
    restart: unless-stopped
    environment:
      RAVEN_Setup_Mode: None
      RAVEN_License_Eula_Accepted: "true"
      RAVEN_Security_UnsecuredAccessAllowed: PublicNetwork
      RAVEN_ServerUrl: http://0.0.0.0:8080
    ports:
      - "127.0.0.1:${PORT}:8080"
      - "${DOCKER_BRIDGE}:${PORT}:8080"
    volumes:
      - data:/var/lib/ravendb/data
      - settings:/etc/ravendb
volumes:
  data:
  settings:
YAML,
    ],
];
