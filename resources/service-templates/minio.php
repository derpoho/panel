<?php

return [
    'name' => 'MinIO',
    'category' => 'Infrastructure',
    'description' => 'S3-compatible object storage. Console on localhost:9001.',
    'icon' => 'minio',
    'kind' => 'compose',
    'port' => 9000,
    'web' => true,
    'port_editable' => true,
    'health' => '/minio/health/live',
    'env' => [
        ['key' => 'MINIO_ROOT_USER', 'label' => 'Root user', 'default' => 'admin'],
        ['key' => 'MINIO_ROOT_PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  minio:
    # minio/minio is gone from Docker Hub; this is the maintained
    # community build of the same server.
    image: pgsty/minio:latest
    restart: unless-stopped
    command: ["server", "/data", "--console-address", ":9001"]
    environment:
      MINIO_ROOT_USER: ${MINIO_ROOT_USER}
      MINIO_ROOT_PASSWORD: ${MINIO_ROOT_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:9000"
      - "127.0.0.1:9001:9001"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
