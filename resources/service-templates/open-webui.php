<?php

return [
    'name' => 'Open WebUI',
    'category' => 'Self-hosted',
    'description' => 'ChatGPT-style interface for local models, with Ollama built in.',
    'icon' => 'ollama',
    'kind' => 'compose',
    'port' => 3080,
    'web' => true,
    'port_editable' => true,
    'health' => '/health',
    // Its first start downloads models and sets up its database, which
    // takes a few minutes without a GPU: about five minutes of checks.
    'health_retries' => 20,
    'notes' => 'The first account you create becomes the admin. Pull a model in the settings; without a GPU, pick a small one.',
    'env' => [
        ['key' => 'WEBUI_SECRET_KEY', 'label' => 'Secret key', 'default' => '{random:32}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  open-webui:
    image: ghcr.io/open-webui/open-webui:ollama
    restart: unless-stopped
    environment:
      WEBUI_SECRET_KEY: ${WEBUI_SECRET_KEY}
      # Its Ollama runs inside this container; without this it looks for
      # one in a container called "ollama", which isn't there.
      OLLAMA_BASE_URL: http://localhost:11434
    ports:
      - "127.0.0.1:${PORT}:8080"
    volumes:
      - ollama:/root/.ollama
      - data:/app/backend/data
volumes:
  ollama:
  data:
YAML,
    ],
];
