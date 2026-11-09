<?php

return [
    'name' => 'Keycloak',
    'category' => 'Infrastructure',
    'description' => 'Single sign-on and identity: OpenID Connect, SAML, users and roles.',
    'icon' => 'keycloak',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/realms/master',
    'notes' => 'The admin console is at /admin. Add a domain: logins need HTTPS outside localhost.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'KEYCLOAK_ADMIN', 'label' => 'Admin user', 'default' => 'admin'],
        ['key' => 'KEYCLOAK_ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  keycloak:
    image: keycloak/keycloak:26.0
    restart: unless-stopped
    depends_on: [db]
    command: ["start"]
    environment:
      KC_HOSTNAME: ${APP_URL}
      KC_HTTP_ENABLED: "true"
      KC_PROXY_HEADERS: xforwarded
      KC_DB: postgres
      KC_DB_URL: jdbc:postgresql://db:5432/keycloak
      KC_DB_USERNAME: keycloak
      KC_DB_PASSWORD: ${DB_PASSWORD}
      KC_BOOTSTRAP_ADMIN_USERNAME: ${KEYCLOAK_ADMIN}
      KC_BOOTSTRAP_ADMIN_PASSWORD: ${KEYCLOAK_ADMIN_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:8080"
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: keycloak
      POSTGRES_USER: keycloak
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  db:
YAML,
    ],
];
