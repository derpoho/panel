<?php

return [
    'name' => 'Home Assistant',
    'category' => 'Self-hosted',
    'description' => 'Home automation for your lights, sensors and devices.',
    'icon' => 'homeassistant',
    'kind' => 'compose',
    'port' => 8123,
    'web' => true,
    'port_editable' => true,
    'health' => '/manifest.json',
    'notes' => 'Create the owner account in the browser. Runs on a bridge network, so devices found by local discovery (mDNS, Bluetooth) may need adding by IP.',
    'env' => [
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  homeassistant:
    image: ghcr.io/home-assistant/home-assistant:stable
    restart: unless-stopped
    environment:
      TZ: ${TIMEZONE}
    # On first boot, start from a config that trusts the ServerOS proxy.
    entrypoint:
      - sh
      - -c
      - |
        if [ ! -f /config/configuration.yaml ]; then
          cat > /config/configuration.yaml <<'CONF'
        default_config:

        http:
          use_x_forwarded_for: true
          trusted_proxies:
            - 127.0.0.1
            - ::1
            - 10.0.0.0/8
            - 172.16.0.0/12
            - 192.168.0.0/16
        CONF
        fi
        exec /init
    ports:
      - "127.0.0.1:${PORT}:8123"
    volumes:
      - config:/config
volumes:
  config:
YAML,
    ],
];
