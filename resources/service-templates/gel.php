<?php

return [
    'name' => 'Gel',
    'category' => 'Databases',
    'description' => 'Gel, formerly EdgeDB: a graph-relational database built on Postgres.',
    'icon' => 'database',
    'kind' => 'compose',
    'port' => 5656,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Connect at gel://admin:PASSWORD@127.0.0.1:PORT/main. It uses a self-signed certificate, so set tls_security=insecure in the client or the DSN. It only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'GEL_SERVER_PASSWORD', 'label' => 'Admin password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  gel:
    image: geldata/gel:7
    restart: unless-stopped
    environment:
      GEL_SERVER_PASSWORD: ${GEL_SERVER_PASSWORD}
      GEL_SERVER_TLS_CERT_MODE: generate_self_signed
    ports:
      - "127.0.0.1:${PORT}:5656"
      - "${DOCKER_BRIDGE}:${PORT}:5656"
    volumes:
      - data:/var/lib/gel/data
volumes:
  data:
YAML,
    ],
];
