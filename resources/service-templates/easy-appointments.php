<?php

return [
    'name' => 'Easy!Appointments',
    'category' => 'Self-hosted',
    'description' => 'Online booking page for appointments, with Google Calendar sync.',
    'icon' => 'calendar',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Open it in the browser to run the installer and create the admin account. Add SMTP settings as extra environment (MAIL_PROTOCOL=smtp, MAIL_SMTP_HOST and so on) to send booking emails.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  easyappointments:
    image: alextselegidis/easyappointments:latest
    restart: unless-stopped
    depends_on: [db]
    environment:
      BASE_URL: ${APP_URL}
      DEBUG_MODE: "FALSE"
      DB_HOST: db
      DB_NAME: easyappointments
      DB_USERNAME: easyappointments
      DB_PASSWORD: ${DB_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - storage:/var/www/html/storage
  db:
    image: mysql:8.4
    restart: unless-stopped
    environment:
      MYSQL_DATABASE: easyappointments
      MYSQL_USER: easyappointments
      MYSQL_PASSWORD: ${DB_PASSWORD}
      MYSQL_ROOT_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/mysql
volumes:
  storage:
  db:
YAML,
    ],
];
