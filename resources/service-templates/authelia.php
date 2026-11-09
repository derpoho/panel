<?php

return [
    'name' => 'Authelia',
    'category' => 'Infrastructure',
    'description' => 'Login portal with two-factor auth that guards your other apps through your proxy.',
    'icon' => 'authelia',
    'kind' => 'compose',
    'port' => 9091,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/health',
    'notes' => 'Give it a domain, like auth.example.com, and set the cookie domain to its parent (example.com). It needs HTTPS. The first user is admin with the password changeme: change the hash in users_database.yml straight away (authelia crypto hash generate argon2). Emails like password resets are written to /data/notification.txt until you set up SMTP.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'COOKIE_DOMAIN', 'label' => 'Cookie domain', 'required' => true, 'hint' => 'The parent domain of the apps it protects, like example.com'],
        ['key' => 'JWT_SECRET', 'label' => 'Reset password JWT secret', 'default' => '{random:64}', 'secret' => true],
        ['key' => 'SESSION_SECRET', 'label' => 'Session secret', 'default' => '{random:64}', 'secret' => true],
        ['key' => 'STORAGE_KEY', 'label' => 'Storage encryption key', 'default' => '{random:64}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  authelia:
    image: authelia/authelia:latest
    restart: unless-stopped
    environment:
      X_AUTHELIA_CONFIG_FILTERS: template
      PUBLIC_URL: ${APP_URL}
      COOKIE_DOMAIN: ${COOKIE_DOMAIN}
      AUTHELIA_IDENTITY_VALIDATION_RESET_PASSWORD_JWT_SECRET: ${JWT_SECRET}
      AUTHELIA_SESSION_SECRET: ${SESSION_SECRET}
      AUTHELIA_STORAGE_ENCRYPTION_KEY: ${STORAGE_KEY}
    ports:
      - "127.0.0.1:${PORT}:9091"
    volumes:
      - ./configuration.yml:/config/configuration.yml:ro
      - ./users_database.yml:/config/users_database.yml
      - data:/data
volumes:
  data:
YAML,
        'configuration.yml' => <<<'YAML'
server:
  address: 'tcp://:9091'

log:
  level: info

theme: auto

authentication_backend:
  file:
    path: /config/users_database.yml
    watch: true

access_control:
  default_policy: one_factor

session:
  cookies:
    - domain: '{{ env "COOKIE_DOMAIN" }}'
      authelia_url: '{{ env "PUBLIC_URL" }}'

regulation:
  max_retries: 5
  find_time: 2m
  ban_time: 10m

storage:
  local:
    path: /data/db.sqlite3

notifier:
  filesystem:
    filename: /data/notification.txt
YAML,
        'users_database.yml' => <<<'YAML'
users:
  admin:
    disabled: false
    displayname: Admin
    password: '$argon2id$v=19$m=65536,t=3,p=4$pBKu+mlthvUj5VrdzG4mOg$E+0kmmGNU2Rrtsynk1pb6ox4Qo7CcmWqc4HV7vWrDGc'
    email: admin@example.com
    groups:
      - admins
YAML,
    ],
];
