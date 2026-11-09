<?php

return [
    'name' => 'Homarr',
    'category' => 'Self-hosted',
    'description' => 'A drag and drop dashboard for your self-hosted apps.',
    'icon' => 'homarr',
    'kind' => 'compose',
    'port' => 7575,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/health/live',
    'notes' => 'Open it in the browser to run the onboarding and create the admin account.',
    'env' => [
        ['key' => 'ENCRYPTION_SEED', 'label' => 'Encryption secret', 'default' => '{random:48}', 'secret' => true, 'hint' => 'Changing it later makes saved integration secrets unreadable'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  homarr:
    image: ghcr.io/homarr-labs/homarr:latest
    restart: unless-stopped
    entrypoint: ["/bin/sh", "-c", "export SECRET_ENCRYPTION_KEY=$$(printf %s \"$$ENCRYPTION_SEED\" | sha256sum | cut -c1-64) && exec /app/entrypoint.sh sh run.sh"]
    environment:
      ENCRYPTION_SEED: ${ENCRYPTION_SEED}
    ports:
      - "127.0.0.1:${PORT}:7575"
    volumes:
      - appdata:/appdata
volumes:
  appdata:
YAML,
    ],
];
