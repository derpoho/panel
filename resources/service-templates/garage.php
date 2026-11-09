<?php

return [
    'name' => 'Garage',
    'category' => 'Infrastructure',
    'description' => 'Lightweight S3-compatible object storage, one node.',
    'icon' => 'box',
    'kind' => 'compose',
    'port' => 3900,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Garage needs a layout before it stores anything. In the garage container run `/garage status` to get the node id, then `/garage layout assign -z dc1 -c 100G <node id>` and `/garage layout apply --version 1`. Make a bucket with `/garage bucket create mybucket`, a key with `/garage key create mykey`, and allow it with `/garage bucket allow --read --write mybucket --key mykey`. Apps on this machine reach the S3 API at {bridge}:PORT, region garage.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  init:
    image: busybox:latest
    command:
      - sh
      - -c
      - '[ -s /secrets/rpc ] || { head -c 32 /dev/urandom | od -An -tx1 | tr -d " \n" > /secrets/rpc; chmod 600 /secrets/rpc; }'
    volumes:
      - secrets:/secrets
  garage:
    image: dxflrs/garage:v2.1.0
    restart: unless-stopped
    depends_on:
      init:
        condition: service_completed_successfully
    ports:
      - "127.0.0.1:${PORT}:3900"
      - "${DOCKER_BRIDGE}:${PORT}:3900"
    volumes:
      - ./garage.toml:/etc/garage.toml:ro
      - secrets:/secrets
      - meta:/var/lib/garage/meta
      - data:/var/lib/garage/data
volumes:
  secrets:
  meta:
  data:
YAML,
        'garage.toml' => <<<'TOML'
metadata_dir = "/var/lib/garage/meta"
data_dir = "/var/lib/garage/data"
db_engine = "lmdb"
metadata_auto_snapshot_interval = "6h"

replication_factor = 1

rpc_bind_addr = "[::]:3901"
rpc_public_addr = "127.0.0.1:3901"
rpc_secret_file = "/secrets/rpc"

[s3_api]
s3_region = "garage"
api_bind_addr = "[::]:3900"
root_domain = ".s3.garage.localhost"
TOML,
    ],
];
