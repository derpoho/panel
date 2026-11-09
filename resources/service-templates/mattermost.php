<?php

return [
    'name' => 'Mattermost',
    'category' => 'Self-hosted',
    'description' => 'Team chat with channels, threads and integrations, with Postgres.',
    'icon' => 'mattermost',
    'kind' => 'compose',
    'port' => 8065,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/v4/system/ping',
    'notes' => 'The first account you create becomes the system admin.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  mattermost:
    image: mattermost/mattermost-team-edition:10.5
    restart: unless-stopped
    depends_on: [db]
    environment:
      MM_SQLSETTINGS_DRIVERNAME: postgres
      MM_SQLSETTINGS_DATASOURCE: postgres://mattermost:${DB_PASSWORD}@db:5432/mattermost?sslmode=disable&connect_timeout=10
      MM_SERVICESETTINGS_SITEURL: ${APP_URL}
    ports:
      - "127.0.0.1:${PORT}:8065"
    volumes:
      - config:/mattermost/config
      - data:/mattermost/data
      - logs:/mattermost/logs
      - plugins:/mattermost/plugins
      - client-plugins:/mattermost/client/plugins
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: mattermost
      POSTGRES_USER: mattermost
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  config:
  data:
  logs:
  plugins:
  client-plugins:
  db:
YAML,
    ],
];
