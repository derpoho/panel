<?php

return [
    'name' => 'OpenBao',
    'category' => 'Infrastructure',
    'description' => 'Open source fork of Vault for secrets and keys, stored on disk.',
    'icon' => 'openbao',
    'kind' => 'compose',
    'port' => 8200,
    'web' => true,
    'port_editable' => true,
    'health' => '/v1/sys/health?uninitcode=200&sealedcode=200',
    'notes' => 'Open /ui to initialise it and save the unseal keys and root token somewhere safe: they are shown once. It starts sealed after every restart, so unseal it again from the UI.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  openbao:
    image: openbao/openbao:latest
    restart: unless-stopped
    command: ["server", "-config=/etc/openbao/openbao.hcl"]
    cap_add: [IPC_LOCK]
    ports:
      - "127.0.0.1:${PORT}:8200"
    volumes:
      - ./openbao.hcl:/etc/openbao/openbao.hcl:ro
      - data:/openbao/file
volumes:
  data:
YAML,
        'openbao.hcl' => <<<'HCL'
storage "file" {
  path = "/openbao/file"
}

listener "tcp" {
  address     = "0.0.0.0:8200"
  tls_disable = true
}

ui            = true
disable_mlock = true
api_addr      = "http://127.0.0.1:8200"
HCL,
    ],
];
