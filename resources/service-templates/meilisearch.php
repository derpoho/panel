<?php

return [
    'name' => 'Meilisearch',
    'category' => 'Infrastructure',
    'description' => 'Fast, typo-tolerant search with a master key.',
    'icon' => 'meilisearch',
    'kind' => 'compose',
    'port' => 7700,
    'web' => true,
    'port_editable' => true,
    'health' => '/health',
    'env' => [
        ['key' => 'MEILI_MASTER_KEY', 'label' => 'Master key', 'default' => '{random:32}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  meilisearch:
    image: getmeili/meilisearch:v1.11
    restart: unless-stopped
    environment:
      MEILI_MASTER_KEY: ${MEILI_MASTER_KEY}
      MEILI_ENV: production
    ports:
      - "127.0.0.1:${PORT}:7700"
    volumes:
      - data:/meili_data
volumes:
  data:
YAML,
    ],
];
