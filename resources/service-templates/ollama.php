<?php

return [
    'name' => 'Ollama',
    'category' => 'Infrastructure',
    'description' => 'Run open AI models like Llama, Gemma and Mistral behind one API, from the official image.',
    'icon' => 'ollama',
    'kind' => 'compose',
    'port' => 11434,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Pull a model from this service’s console, like ollama pull llama3.2, then call http://127.0.0.1:PORT/api/chat from this machine. It has no login, so it only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1. Without a GPU, stick to small models: about 8 GB of RAM runs a 3B to 8B model. For a chat window, deploy Open WebUI instead.',
    'env' => [
        ['key' => 'OLLAMA_KEEP_ALIVE', 'label' => 'Keep a model loaded for', 'default' => '5m', 'hint' => 'How long a model stays in memory after its last request, like 5m or 1h. -1 keeps it loaded.'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  ollama:
    image: ollama/ollama:latest
    restart: unless-stopped
    environment:
      OLLAMA_KEEP_ALIVE: ${OLLAMA_KEEP_ALIVE}
    ports:
      - "127.0.0.1:${PORT}:11434"
      - "${DOCKER_BRIDGE}:${PORT}:11434"
    volumes:
      - models:/root/.ollama
volumes:
  models:
YAML,
    ],
];
