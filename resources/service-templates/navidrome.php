<?php

return [
    'name' => 'Navidrome',
    'category' => 'Self-hosted',
    'description' => 'Music streaming server, compatible with Subsonic apps.',
    'icon' => 'music',
    'kind' => 'compose',
    'port' => 4533,
    'web' => true,
    'port_editable' => true,
    'health' => '/ping',
    'notes' => 'Create the admin account in the browser. Music goes in the music volume.',
    'env' => [
        ['key' => 'SCAN_SCHEDULE', 'label' => 'Library scan', 'default' => '1h'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  navidrome:
    image: deluan/navidrome:latest
    restart: unless-stopped
    environment:
      ND_SCANSCHEDULE: ${SCAN_SCHEDULE}
    ports:
      - "127.0.0.1:${PORT}:4533"
    volumes:
      - data:/data
      - music:/music
volumes:
  data:
  music:
YAML,
    ],
];
