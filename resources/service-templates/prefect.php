<?php

return [
    'name' => 'Prefect',
    'category' => 'Infrastructure',
    'description' => 'Python workflow orchestration server and UI, with Postgres.',
    'icon' => 'prefect',
    'kind' => 'compose',
    'port' => 4200,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'The UI and API ask for admin:PASSWORD, using the password below. Workers set PREFECT_API_URL to your URL plus /api, or http://{bridge}:PORT/api from this machine, and PREFECT_API_AUTH_STRING to the same admin:PASSWORD.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:20}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  prefect:
    image: prefecthq/prefect:3-latest
    restart: unless-stopped
    depends_on: [db]
    command: ["prefect", "server", "start", "--host", "0.0.0.0"]
    environment:
      PREFECT_API_DATABASE_CONNECTION_URL: postgresql+asyncpg://prefect:${DB_PASSWORD}@db:5432/prefect
      PREFECT_UI_API_URL: ${APP_URL}/api
      PREFECT_SERVER_API_AUTH_STRING: admin:${ADMIN_PASSWORD}
      PREFECT_SERVER_ANALYTICS_ENABLED: "false"
    ports:
      - "127.0.0.1:${PORT}:4200"
      - "${DOCKER_BRIDGE}:${PORT}:4200"
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: prefect
      POSTGRES_USER: prefect
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  db:
YAML,
    ],
];
