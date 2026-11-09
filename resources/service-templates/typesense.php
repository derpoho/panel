<?php

return [
    'name' => 'Typesense',
    'category' => 'Infrastructure',
    'description' => 'Open source search engine with an API key.',
    'icon' => 'typesense',
    'kind' => 'compose',
    'port' => 8108,
    'web' => true,
    'port_editable' => true,
    'health' => '/health',
    'env' => [
        ['key' => 'TYPESENSE_API_KEY', 'label' => 'API key', 'default' => '{random:32}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  typesense:
    image: typesense/typesense:27.1
    restart: unless-stopped
    command: ["--data-dir", "/data", "--api-key", "${TYPESENSE_API_KEY}", "--enable-cors"]
    ports:
      - "127.0.0.1:${PORT}:8108"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
