<?php

return [
    'name' => 'Leantime',
    'category' => 'Self-hosted',
    'description' => 'Project management with tasks, timelines and goals, built for non-project managers.',
    'icon' => 'workflow',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Open /install in the browser to set up the database and create the admin account.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'SESSION_PASSWORD', 'label' => 'Session secret', 'default' => '{random:40}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  leantime:
    image: leantime/leantime:latest
    restart: unless-stopped
    depends_on:
      db:
        condition: service_healthy
    security_opt:
      - no-new-privileges:true
    cap_add:
      - CAP_CHOWN
      - CAP_SETGID
      - CAP_SETUID
    environment:
      LEAN_APP_URL: ${APP_URL}
      LEAN_SESSION_PASSWORD: ${SESSION_PASSWORD}
      LEAN_SESSION_SECURE: "false"
      LEAN_DEFAULT_TIMEZONE: ${TIMEZONE}
      LEAN_DB_HOST: db
      LEAN_DB_PORT: 3306
      LEAN_DB_DATABASE: leantime
      LEAN_DB_USER: leantime
      LEAN_DB_PASSWORD: ${DB_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:8080"
    volumes:
      - public-userfiles:/var/www/html/public/userfiles
      - userfiles:/var/www/html/userfiles
      - plugins:/var/www/html/app/Plugins
      - logs:/var/www/html/storage/logs
  db:
    image: mysql:8.4
    restart: unless-stopped
    command: ["--character-set-server=utf8mb4", "--collation-server=utf8mb4_unicode_ci"]
    environment:
      MYSQL_DATABASE: leantime
      MYSQL_USER: leantime
      MYSQL_PASSWORD: ${DB_PASSWORD}
      MYSQL_ROOT_PASSWORD: ${DB_PASSWORD}
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "127.0.0.1", "--silent"]
      interval: 10s
      timeout: 10s
      retries: 10
      start_period: 60s
    volumes:
      - db:/var/lib/mysql
volumes:
  public-userfiles:
  userfiles:
  plugins:
  logs:
  db:
YAML,
    ],
];
