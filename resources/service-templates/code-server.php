<?php

return [
    'name' => 'code-server',
    'category' => 'Infrastructure',
    'description' => 'VS Code in the browser, with a password.',
    'icon' => 'code',
    'kind' => 'compose',
    'port' => 8443,
    'web' => true,
    'port_editable' => true,
    'health' => '/healthz',
    'notes' => 'Your workspace is kept in a volume at /config/workspace. Add a domain: some editor features need HTTPS.',
    'env' => [
        ['key' => 'PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  code-server:
    image: linuxserver/code-server:latest
    restart: unless-stopped
    environment:
      PUID: "1000"
      PGID: "1000"
      TZ: ${TIMEZONE}
      PASSWORD: ${PASSWORD}
      DEFAULT_WORKSPACE: /config/workspace
    ports:
      - "127.0.0.1:${PORT}:8443"
    volumes:
      - config:/config
volumes:
  config:
YAML,
    ],
];
