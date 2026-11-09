<?php

return [
    'name' => 'Redis Insight',
    'category' => 'Infrastructure',
    'description' => 'Redis\'s own GUI for browsing keys, running commands and watching memory.',
    'icon' => 'redis',
    'kind' => 'compose',
    'port' => 5540,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/health',
    'notes' => 'It has no login, so keep it off public domains or put access control in front. For Redis on this machine, add a database at {bridge} and its port.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  redisinsight:
    image: redis/redisinsight:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:5540"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
