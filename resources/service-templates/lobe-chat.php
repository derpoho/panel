<?php

return [
    'name' => 'LobeChat',
    'category' => 'Self-hosted',
    'description' => 'Polished AI chat app for OpenAI, Anthropic, Ollama and more.',
    'icon' => 'bot',
    'kind' => 'compose',
    'port' => 3210,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Chats are kept in each person\'s browser. The access code is asked for before anyone can use your API keys.',
    'env' => [
        ['key' => 'ACCESS_CODE', 'label' => 'Access code', 'default' => '{random:16}', 'secret' => true],
        ['key' => 'OPENAI_API_KEY', 'label' => 'OpenAI API key', 'default' => '', 'secret' => true],
        ['key' => 'ANTHROPIC_API_KEY', 'label' => 'Anthropic API key', 'default' => '', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  lobe-chat:
    image: lobehub/lobe-chat:latest
    restart: unless-stopped
    environment:
      ACCESS_CODE: ${ACCESS_CODE}
      OPENAI_API_KEY: ${OPENAI_API_KEY}
      ANTHROPIC_API_KEY: ${ANTHROPIC_API_KEY}
    ports:
      - "127.0.0.1:${PORT}:3210"
YAML,
    ],
];
