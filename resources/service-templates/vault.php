<?php

return [
    'name' => 'Vault',
    'category' => 'Infrastructure',
    'description' => 'HashiCorp Vault for secrets, keys and certificates, stored on disk.',
    'icon' => 'vault',
    'kind' => 'compose',
    'port' => 8200,
    'web' => true,
    'port_editable' => true,
    'health' => '/v1/sys/health?uninitcode=200&sealedcode=200',
    'notes' => 'Open /ui to initialise it and save the unseal keys and root token somewhere safe: they are shown once. Vault starts sealed after every restart, so unseal it again from the UI.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  vault:
    image: hashicorp/vault:latest
    restart: unless-stopped
    command: ["server"]
    cap_add: [IPC_LOCK]
    environment:
      VAULT_LOCAL_CONFIG: '{"storage": {"file": {"path": "/vault/file"}}, "listener": [{"tcp": {"address": "0.0.0.0:8200", "tls_disable": true}}], "ui": true, "disable_mlock": true, "api_addr": "http://127.0.0.1:8200"}'
    ports:
      - "127.0.0.1:${PORT}:8200"
    volumes:
      - data:/vault/file
volumes:
  data:
YAML,
    ],
];
