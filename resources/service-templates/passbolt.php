<?php

return [
    'name' => 'Passbolt',
    'category' => 'Self-hosted',
    'description' => 'A password manager built for teams, with MariaDB.',
    'icon' => 'passbolt',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/healthcheck/status.json',
    'notes' => 'It needs a domain with HTTPS. Make the first admin by running su -m -c "/usr/share/php/passbolt/bin/cake passbolt register_user -u you@example.com -f First -l Last -r admin" -s /bin/sh www-data in the passbolt container, then open the link it prints.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  passbolt:
    image: passbolt/passbolt:latest-ce
    restart: unless-stopped
    depends_on: [db]
    command: ["/usr/bin/wait-for.sh", "-t", "0", "db:3306", "--", "/docker-entrypoint.sh"]
    environment:
      APP_FULL_BASE_URL: ${APP_URL}
      PASSBOLT_SSL_FORCE: "false"
      DATASOURCES_DEFAULT_HOST: db
      DATASOURCES_DEFAULT_USERNAME: passbolt
      DATASOURCES_DEFAULT_PASSWORD: ${DB_PASSWORD}
      DATASOURCES_DEFAULT_DATABASE: passbolt
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - gpg:/etc/passbolt/gpg
      - jwt:/etc/passbolt/jwt
  db:
    image: mariadb:11
    restart: unless-stopped
    environment:
      MARIADB_DATABASE: passbolt
      MARIADB_USER: passbolt
      MARIADB_PASSWORD: ${DB_PASSWORD}
      MARIADB_RANDOM_ROOT_PASSWORD: "1"
    volumes:
      - db:/var/lib/mysql
volumes:
  gpg:
  jwt:
  db:
YAML,
    ],
];
