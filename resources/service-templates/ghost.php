<?php

return [
    'name' => 'Ghost',
    'category' => 'Self-hosted',
    'description' => 'Publishing and newsletters, with a MySQL database.',
    'icon' => 'ghost',
    'kind' => 'compose',
    'port' => 2368,
    'web' => true,
    'port_editable' => true,
    'health' => '/ghost/api/admin/site/',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Site URL', 'default' => '{url}'],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  ghost:
    image: ghost:5-alpine
    restart: unless-stopped
    depends_on: [db]
    environment:
      url: ${APP_URL}
      database__client: mysql
      database__connection__host: db
      database__connection__user: ghost
      database__connection__password: ${DB_PASSWORD}
      database__connection__database: ghost
    ports:
      - "127.0.0.1:${PORT}:2368"
    volumes:
      - content:/var/lib/ghost/content
  db:
    image: mysql:8.0
    restart: unless-stopped
    environment:
      MYSQL_DATABASE: ghost
      MYSQL_USER: ghost
      MYSQL_PASSWORD: ${DB_PASSWORD}
      MYSQL_RANDOM_ROOT_PASSWORD: "1"
    volumes:
      - db:/var/lib/mysql
volumes:
  content:
  db:
YAML,
    ],
];
