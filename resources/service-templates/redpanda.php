<?php

return [
    'name' => 'Redpanda',
    'category' => 'Infrastructure',
    'description' => 'Kafka-compatible streaming in a single binary, no ZooKeeper.',
    'icon' => 'redpanda',
    'kind' => 'compose',
    'port' => 9092,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Kafka clients on this machine connect to 127.0.0.1:PORT. Runs as a single node, which suits development and small workloads.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  redpanda:
    image: redpandadata/redpanda:latest
    restart: unless-stopped
    command:
      - redpanda
      - start
      - --mode=dev-container
      - --smp=1
      - --kafka-addr=0.0.0.0:9092
      - --advertise-kafka-addr=127.0.0.1:${PORT}
    ports:
      - "127.0.0.1:${PORT}:9092"
    volumes:
      - data:/var/lib/redpanda/data
volumes:
  data:
YAML,
    ],
];
