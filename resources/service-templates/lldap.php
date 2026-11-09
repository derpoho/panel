<?php

return [
    'name' => 'LLDAP',
    'category' => 'Infrastructure',
    'description' => 'Light LDAP server for users and groups, with a simple web UI.',
    'icon' => 'shield',
    'kind' => 'compose',
    'port' => 17170,
    'web' => true,
    'port_editable' => true,
    'health' => '/health',
    'notes' => 'Sign in as admin with the admin password from your settings. Apps on this machine bind to ldap://{bridge} on the LDAP port as uid=admin,ou=people,BASE_DN.',
    'env' => [
        ['key' => 'BASE_DN', 'label' => 'Base DN', 'default' => 'dc=example,dc=com'],
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:20}', 'secret' => true],
        ['key' => 'JWT_SECRET', 'label' => 'JWT secret', 'default' => '{random:48}', 'secret' => true],
        ['key' => 'KEY_SEED', 'label' => 'Key seed', 'default' => '{random:48}', 'secret' => true],
        ['key' => 'LDAP_PORT', 'label' => 'LDAP port', 'default' => '3890'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  lldap:
    image: lldap/lldap:stable
    restart: unless-stopped
    environment:
      LLDAP_JWT_SECRET: ${JWT_SECRET}
      LLDAP_KEY_SEED: ${KEY_SEED}
      LLDAP_LDAP_BASE_DN: ${BASE_DN}
      LLDAP_LDAP_USER_PASS: ${ADMIN_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:17170"
      - "127.0.0.1:${LDAP_PORT}:3890"
      - "${DOCKER_BRIDGE}:${LDAP_PORT}:3890"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
