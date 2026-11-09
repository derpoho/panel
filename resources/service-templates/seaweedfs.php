<?php

return [
    'name' => 'SeaweedFS',
    'category' => 'Infrastructure',
    'description' => 'Fast distributed file store with an S3 API, here as a single server.',
    'icon' => 'box',
    'kind' => 'compose',
    'port' => 8333,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'The main port is the S3 API. Apps on this machine reach it at {bridge}:PORT. It has no credentials set, so it only listens locally and on the Docker bridge. The master UI is on localhost:9333 and the filer on localhost:8888.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  seaweedfs:
    image: chrislusf/seaweedfs:latest
    restart: unless-stopped
    command: ["server", "-dir=/data", "-s3", "-ip.bind=0.0.0.0"]
    ports:
      - "127.0.0.1:${PORT}:8333"
      - "${DOCKER_BRIDGE}:${PORT}:8333"
      - "127.0.0.1:9333:9333"
      - "127.0.0.1:8888:8888"
    volumes:
      - data:/data
volumes:
  data:
YAML,
    ],
];
