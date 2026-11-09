<?php

return [
    'name' => 'SiYuan',
    'category' => 'Self-hosted',
    'description' => 'Block-based note taking and personal knowledge base.',
    'icon' => 'siyuan',
    'kind' => 'compose',
    'port' => 6806,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Unlock it with the access code below.',
    'env' => [
        ['key' => 'AUTH_CODE', 'label' => 'Access code', 'default' => '{random:16}', 'secret' => true],
        ['key' => 'TIMEZONE', 'label' => 'Timezone', 'default' => 'UTC'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  siyuan:
    image: b3log/siyuan:latest
    restart: unless-stopped
    command: ["--workspace=/siyuan/workspace/", "--accessAuthCode=${AUTH_CODE}"]
    environment:
      TZ: ${TIMEZONE}
      PUID: 1000
      PGID: 1000
    ports:
      - "127.0.0.1:${PORT}:6806"
    volumes:
      - workspace:/siyuan/workspace
volumes:
  workspace:
YAML,
    ],
];
