<?php

return [
    'name' => 'LibreSpeed',
    'category' => 'Self-hosted',
    'description' => 'Speed test you host yourself, to check the connection to your server.',
    'icon' => 'activity',
    'kind' => 'compose',
    'port' => 80,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Past results are at /results/stats.php, behind the stats password.',
    'env' => [
        ['key' => 'PASSWORD', 'label' => 'Stats password', 'default' => '{random:16}', 'secret' => true],
        ['key' => 'TZ', 'label' => 'Timezone', 'default' => 'Etc/UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  librespeed:
    image: lscr.io/linuxserver/librespeed:latest
    restart: unless-stopped
    environment:
      PUID: "1000"
      PGID: "1000"
      TZ: ${TZ}
      PASSWORD: ${PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - config:/config
volumes:
  config:
YAML,
    ],
];
