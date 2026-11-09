<?php

return [
    'name' => '7 Days to Die',
    'category' => 'Game servers',
    'description' => 'Dedicated 7 Days to Die server on LinuxGSM, updated on every start.',
    'icon' => 'sdtd',
    'kind' => 'compose',
    'port' => 26900,
    'web' => false,
    'port_editable' => false,
    'health' => null,
    'notes' => 'Uses TCP port 26900 and UDP ports 26900 to 26902, open to the internet. Needs an x86 (amd64) machine with about 15 GB of disk and 8 GB of RAM, and the first start downloads the game, so give it a while. The server name, password and world settings are in serverfiles/sdtdserver.xml: change them there, then restart.',
    'env' => [
        ['key' => 'VERSION', 'label' => 'Game branch', 'default' => 'stable', 'hint' => 'stable, or latest_experimental for the newest build.'],
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  sdtd:
    image: vinanrra/7dtd-server:latest
    restart: unless-stopped
    stop_grace_period: 1m
    environment:
      START_MODE: "3"
      VERSION: ${VERSION}
      PUID: "1000"
      PGID: "1000"
      TimeZone: ${TIMEZONE}
      MONITOR: "YES"
      BACKUP: "NO"
      TEST_ALERT: "NO"
    ports:
      - "26900:26900/tcp"
      - "26900:26900/udp"
      - "26901:26901/udp"
      - "26902:26902/udp"
    ulimits:
      nofile:
        soft: 10240
        hard: 10240
    volumes:
      - saves:/home/sdtdserver/.local/share/7DaysToDie
      - config:/home/sdtdserver/lgsm/config-lgsm/sdtdserver
      - serverfiles:/home/sdtdserver/serverfiles
      - logs:/home/sdtdserver/log
      - backups:/home/sdtdserver/lgsm/backup
volumes:
  saves:
  config:
  serverfiles:
  logs:
  backups:
YAML,
    ],
];
