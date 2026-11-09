<?php

return [
    'name' => 'LibreTranslate',
    'category' => 'Self-hosted',
    'description' => 'Machine translation API and web page that runs fully on your server.',
    'icon' => 'libretranslate',
    'kind' => 'compose',
    'port' => 5000,
    'web' => true,
    'port_editable' => true,
    'health' => '/languages',
    'notes' => 'The first start downloads the language models, which can take a while. Fewer languages means less memory.',
    'env' => [
        ['key' => 'LT_LOAD_ONLY', 'label' => 'Languages', 'default' => 'en,es,fr,de', 'hint' => 'Comma separated language codes. Leave empty for all of them.'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  libretranslate:
    image: libretranslate/libretranslate:latest
    restart: unless-stopped
    environment:
      LT_LOAD_ONLY: ${LT_LOAD_ONLY}
      LT_UPDATE_MODELS: "true"
    ports:
      - "127.0.0.1:${PORT}:5000"
    volumes:
      - models:/home/libretranslate/.local
volumes:
  models:
YAML,
    ],
];
