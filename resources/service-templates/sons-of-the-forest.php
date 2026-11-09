<?php

return [
    'name' => 'Sons of the Forest',
    'category' => 'Game servers',
    'description' => 'Dedicated Sons of the Forest server running under Wine, updated on every start.',
    'icon' => 'gamepad',
    'kind' => 'compose',
    'port' => 8766,
    'web' => false,
    'port_editable' => false,
    'health' => null,
    'notes' => 'Uses UDP ports 8766, 9700 and 27016, open to the internet. Needs an x86 (amd64) machine with 4 cores and 8 GB of RAM or more, and the first start downloads the game, so give it a while. The server name, password and player count live in userdata/dedicatedserver.cfg on the data volume; restart after editing it.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  sotf:
    image: jammsen/sons-of-the-forest-dedicated-server:latest
    restart: unless-stopped
    stop_grace_period: 2m
    environment:
      PUID: "1000"
      ALWAYS_UPDATE_ON_START: "true"
      SKIP_NETWORK_ACCESSIBILITY_TEST: "true"
    ports:
      - "8766:8766/udp"
      - "9700:9700/udp"
      - "27016:27016/udp"
    volumes:
      - data:/sonsoftheforest
volumes:
  data:
YAML,
    ],
];
