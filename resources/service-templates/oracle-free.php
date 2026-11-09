<?php

return [
    'name' => 'Oracle Database Free',
    'category' => 'Databases',
    'description' => 'Oracle Database 23ai Free, with an app user in the FREEPDB1 database.',
    'icon' => 'database',
    'kind' => 'compose',
    'port' => 1521,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Connect at 127.0.0.1:PORT/FREEPDB1 as the app user, or as SYSTEM with the admin password. The first start sets up the database and takes a few minutes. It only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'ORACLE_PASSWORD', 'label' => 'Admin password (SYS and SYSTEM)', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'APP_USER', 'label' => 'App user', 'default' => 'app'],
        ['key' => 'APP_USER_PASSWORD', 'label' => 'App user password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  oracle:
    image: gvenzl/oracle-free:23-slim
    restart: unless-stopped
    environment:
      ORACLE_PASSWORD: ${ORACLE_PASSWORD}
      APP_USER: ${APP_USER}
      APP_USER_PASSWORD: ${APP_USER_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:1521"
      - "${DOCKER_BRIDGE}:${PORT}:1521"
    volumes:
      - data:/opt/oracle/oradata
volumes:
  data:
YAML,
    ],
];
