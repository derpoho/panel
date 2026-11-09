<?php

return [
    'name' => 'Forgejo',
    'category' => 'Infrastructure',
    'description' => 'Community-run Git forge with issues, pull requests, packages and Actions.',
    'icon' => 'forgejo',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/healthz',
    'notes' => 'Register on the first visit: the first account becomes the admin, so do it straight away. Git over SSH is open to the internet on the SSH port.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'SSH_PORT', 'label' => 'SSH port', 'default' => '2222'],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  forgejo:
    image: codeberg.org/forgejo/forgejo:17
    restart: unless-stopped
    depends_on: [db]
    environment:
      USER_UID: 1000
      USER_GID: 1000
      FORGEJO__database__DB_TYPE: postgres
      FORGEJO__database__HOST: db:5432
      FORGEJO__database__NAME: forgejo
      FORGEJO__database__USER: forgejo
      FORGEJO__database__PASSWD: ${DB_PASSWORD}
      FORGEJO__server__ROOT_URL: ${APP_URL}
      FORGEJO__server__SSH_PORT: ${SSH_PORT}
      FORGEJO__security__INSTALL_LOCK: "true"
    ports:
      - "127.0.0.1:${PORT}:3000"
      - "${SSH_PORT}:22"
    volumes:
      - data:/data
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: forgejo
      POSTGRES_USER: forgejo
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  data:
  db:
YAML,
    ],
];
