<?php

return [
    'name' => 'pgAdmin',
    'category' => 'Infrastructure',
    'description' => 'Web admin for PostgreSQL: browse, query and manage databases.',
    'icon' => 'pgadmin',
    'kind' => 'compose',
    'port' => 5050,
    'web' => true,
    'port_editable' => true,
    'health' => '/misc/ping',
    'notes' => 'Put it behind a domain only if you need to: it is a login page for your databases.',
    'env' => [
        ['key' => 'PGADMIN_EMAIL', 'label' => 'Login email', 'default' => 'admin@example.com', 'hint' => 'pgAdmin wants an email address as the login.'],
        ['key' => 'PGADMIN_PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  pgadmin:
    image: dpage/pgadmin4:latest
    restart: unless-stopped
    environment:
      PGADMIN_DEFAULT_EMAIL: ${PGADMIN_EMAIL}
      PGADMIN_DEFAULT_PASSWORD: ${PGADMIN_PASSWORD}
      PGADMIN_CONFIG_SERVER_MODE: "True"
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - data:/var/lib/pgadmin
volumes:
  data:
YAML,
    ],
];
