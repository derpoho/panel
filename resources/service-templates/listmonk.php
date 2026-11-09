<?php

return [
    'name' => 'listmonk',
    'category' => 'Self-hosted',
    'description' => 'Newsletter and mailing list manager, with Postgres.',
    'icon' => 'listmonk',
    'kind' => 'compose',
    'port' => 9000,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/health',
    'notes' => 'Open it in the browser to create the admin account, then add your SMTP server under Settings.',
    'env' => [
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  listmonk:
    image: listmonk/listmonk:latest
    restart: unless-stopped
    depends_on: [db]
    command: [sh, -c, "./listmonk --install --idempotent --yes --config '' && ./listmonk --upgrade --yes --config '' && ./listmonk --config ''"]
    environment:
      TZ: ${TIMEZONE}
      LISTMONK_app__address: 0.0.0.0:9000
      LISTMONK_db__host: db
      LISTMONK_db__port: "5432"
      LISTMONK_db__user: listmonk
      LISTMONK_db__password: ${DB_PASSWORD}
      LISTMONK_db__database: listmonk
      LISTMONK_db__ssl_mode: disable
    ports:
      - "127.0.0.1:${PORT}:9000"
    volumes:
      - uploads:/listmonk/uploads
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: listmonk
      POSTGRES_USER: listmonk
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  uploads:
  db:
YAML,
    ],
];
