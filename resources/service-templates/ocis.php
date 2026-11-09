<?php

return [
    'name' => 'ownCloud Infinite Scale',
    'category' => 'Self-hosted',
    'description' => 'File sync and sharing from ownCloud, no database needed.',
    'icon' => 'owncloud',
    'kind' => 'compose',
    'port' => 9200,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Sign in as admin with the admin password below. It needs to be opened on its public URL, not the raw port.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  ocis:
    image: owncloud/ocis:latest
    restart: unless-stopped
    entrypoint: ["/bin/sh"]
    command: ["-c", "ocis init || true; ocis server"]
    environment:
      OCIS_URL: ${APP_URL}
      OCIS_INSECURE: "true"
      PROXY_TLS: "false"
      PROXY_HTTP_ADDR: 0.0.0.0:9200
      IDM_ADMIN_PASSWORD: ${ADMIN_PASSWORD}
      OCIS_LOG_LEVEL: warn
    ports:
      - "127.0.0.1:${PORT}:9200"
    volumes:
      - config:/etc/ocis
      - data:/var/lib/ocis
volumes:
  config:
  data:
YAML,
    ],
];
