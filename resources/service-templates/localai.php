<?php

return [
    'name' => 'LocalAI',
    'category' => 'Infrastructure',
    'description' => 'Run open models on the CPU behind an OpenAI-compatible API.',
    'icon' => 'bot',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/readyz',
    'notes' => 'Install models from the web UI gallery. Clients send the API key as a Bearer token. Models run on the CPU, so give it plenty of RAM.',
    'env' => [
        ['key' => 'API_KEY', 'label' => 'API key', 'default' => '{random:32}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  localai:
    image: localai/localai:latest
    restart: unless-stopped
    environment:
      LOCALAI_API_KEY: ${API_KEY}
      MODELS_PATH: /models
    ports:
      - "127.0.0.1:${PORT}:8080"
    volumes:
      - models:/models
      - backends:/backends
volumes:
  models:
  backends:
YAML,
    ],
];
