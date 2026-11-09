<?php

return [
    'name' => 'Casdoor',
    'category' => 'Infrastructure',
    'description' => 'Single sign-on with a web UI: OAuth, OIDC, SAML, LDAP and social logins.',
    'icon' => 'shield',
    'kind' => 'compose',
    'port' => 8000,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'The first login is admin / 123. Change it straight away. It runs with MariaDB.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  casdoor:
    image: casbin/casdoor:latest
    restart: unless-stopped
    depends_on: [db]
    environment:
      RUNNING_IN_DOCKER: "true"
      driverName: mysql
      dataSourceName: root:${DB_PASSWORD}@tcp(db:3306)/
      dbName: casdoor
      origin: ${APP_URL}
    ports:
      - "127.0.0.1:${PORT}:8000"
  db:
    image: mariadb:11
    restart: unless-stopped
    environment:
      MARIADB_ROOT_PASSWORD: ${DB_PASSWORD}
      MARIADB_DATABASE: casdoor
    volumes:
      - db:/var/lib/mysql
volumes:
  db:
YAML,
    ],
];
