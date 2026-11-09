<?php

return [
    'name' => 'GoToSocial',
    'category' => 'Self-hosted',
    'description' => 'A small fediverse server that talks to Mastodon, for you and a few friends.',
    'icon' => 'globe',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/livez',
    'notes' => 'The domain cannot change later without starting over. Make your account from the service console with /gotosocial/gotosocial admin account create --username you --email you@example.com --password PASSWORD, then admin account promote --username you, and restart the service.',
    'env' => [
        ['key' => 'DOMAIN', 'label' => 'Domain', 'default' => '', 'required' => true, 'hint' => 'The domain this service is served on, like social.example.com. Handles end in it.'],
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  gotosocial:
    image: superseriousbusiness/gotosocial:latest
    restart: unless-stopped
    environment:
      TZ: ${TIMEZONE}
      GTS_HOST: ${DOMAIN}
      GTS_PROTOCOL: https
      GTS_DB_TYPE: sqlite
      GTS_DB_ADDRESS: /gotosocial/storage/sqlite.db
      GTS_LETSENCRYPT_ENABLED: "false"
      GTS_TRUSTED_PROXIES: 127.0.0.1/32,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16
    ports:
      - "127.0.0.1:${PORT}:8080"
    volumes:
      - data:/gotosocial/storage
volumes:
  data:
YAML,
    ],
];
