<?php

return [
    'name' => 'Milvus',
    'category' => 'Databases',
    'description' => 'Vector database for AI search, standalone with etcd and MinIO.',
    'icon' => 'milvus',
    'kind' => 'compose',
    'port' => 19530,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Connect a Milvus client at http://127.0.0.1:PORT. There is no login, so it only listens locally. Give it a minute or two on first start. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  volume-init:
    image: milvusdb/milvus:v3.0.5
    user: "0:0"
    network_mode: none
    restart: "no"
    entrypoint: ["/bin/sh", "-c"]
    command:
      - |
        set -eu
        uid="$$(id -u milvus)"
        gid="$$(id -g milvus)"
        marker="/var/lib/milvus/.milvus-volume-owner-$${uid}-$${gid}"
        if [ ! -e "$${marker}" ]; then
          chown -R "$${uid}:$${gid}" /var/lib/milvus
          touch "$${marker}"
          chown "$${uid}:$${gid}" "$${marker}"
        fi
    volumes:
      - milvus:/var/lib/milvus
  etcd:
    image: quay.io/coreos/etcd:v3.5.25
    restart: unless-stopped
    environment:
      ETCD_AUTO_COMPACTION_MODE: revision
      ETCD_AUTO_COMPACTION_RETENTION: "1000"
      ETCD_QUOTA_BACKEND_BYTES: "4294967296"
      ETCD_SNAPSHOT_COUNT: "50000"
    command: etcd -advertise-client-urls=http://etcd:2379 -listen-client-urls http://0.0.0.0:2379 --data-dir /etcd
    volumes:
      - etcd:/etcd
  minio:
    image: milvusdb/minio:RELEASE.2024-12-18T13-15-44Z
    restart: unless-stopped
    environment:
      MINIO_ACCESS_KEY: minioadmin
      MINIO_SECRET_KEY: minioadmin
    command: minio server /minio_data
    volumes:
      - minio:/minio_data
  milvus:
    image: milvusdb/milvus:v3.0.5
    restart: unless-stopped
    command: ["milvus", "run", "standalone"]
    security_opt:
      - seccomp:unconfined
    environment:
      ETCD_ENDPOINTS: etcd:2379
      MINIO_ADDRESS: minio:9000
      MINIO_REGION: us-east-1
    depends_on:
      volume-init:
        condition: service_completed_successfully
      etcd:
        condition: service_started
      minio:
        condition: service_started
    ports:
      - "127.0.0.1:${PORT}:19530"
      - "${DOCKER_BRIDGE}:${PORT}:19530"
    volumes:
      - milvus:/var/lib/milvus
volumes:
  milvus:
  etcd:
  minio:
YAML,
    ],
];
