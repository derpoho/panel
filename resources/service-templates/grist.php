<?php

return [
    'name' => 'Grist',
    'category' => 'Self-hosted',
    'description' => 'Spreadsheets with real columns, formulas in Python and a database underneath.',
    'icon' => 'table',
    'kind' => 'compose',
    'port' => 8484,
    'web' => true,
    'port_editable' => true,
    'health' => '/status',
    'notes' => 'There is no sign-in out of the box: anyone with the address works as the owner email below. Set up OIDC or SAML login before sharing it.',
    'env' => [
        ['key' => 'APP_HOME_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'GRIST_DEFAULT_EMAIL', 'label' => 'Owner email', 'default' => 'you@example.com'],
        ['key' => 'GRIST_SESSION_SECRET', 'label' => 'Session secret', 'default' => '{random:32}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  grist:
    image: gristlabs/grist:latest
    restart: unless-stopped
    environment:
      APP_HOME_URL: ${APP_HOME_URL}
      GRIST_DEFAULT_EMAIL: ${GRIST_DEFAULT_EMAIL}
      GRIST_SESSION_SECRET: ${GRIST_SESSION_SECRET}
    ports:
      - "127.0.0.1:${PORT}:8484"
    volumes:
      - persist:/persist
volumes:
  persist:
YAML,
    ],
];
