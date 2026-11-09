<?php

return [
    'name' => 'Kestra',
    'category' => 'Infrastructure',
    'description' => 'Declarative workflow orchestration in YAML, with Postgres.',
    'icon' => 'workflow',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Open it straight after deploying to create the admin login. It mounts the Docker socket so script tasks can run in containers, which gives it control of Docker on this machine.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  kestra:
    image: kestra/kestra:latest
    restart: unless-stopped
    depends_on: [db]
    user: root
    command: server standalone
    environment:
      KESTRA_CONFIGURATION: |
        datasources:
          postgres:
            url: jdbc:postgresql://db:5432/kestra
            driverClassName: org.postgresql.Driver
            username: kestra
            password: ${DB_PASSWORD}
        kestra:
          url: ${APP_URL}
          repository:
            type: postgres
          queue:
            type: postgres
          storage:
            type: local
            local:
              basePath: /app/storage
          tasks:
            tmpDir:
              path: /tmp/kestra-wd/tmp
    ports:
      - "127.0.0.1:${PORT}:8080"
    volumes:
      - storage:/app/storage
      - /var/run/docker.sock:/var/run/docker.sock
      - /tmp/kestra-wd:/tmp/kestra-wd
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: kestra
      POSTGRES_USER: kestra
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  storage:
  db:
YAML,
    ],
];
