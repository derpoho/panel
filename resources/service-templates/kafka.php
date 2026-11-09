<?php

return [
    'name' => 'Apache Kafka',
    'category' => 'Infrastructure',
    'description' => 'Event streaming broker, one node in KRaft mode with no ZooKeeper.',
    'icon' => 'apachekafka',
    'kind' => 'compose',
    'port' => 9092,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Clients connect to {bridge}:PORT, from this machine and from apps you deploy on it. It has no login, so it only listens locally and on the Docker bridge.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  kafka:
    image: apache/kafka:latest
    restart: unless-stopped
    environment:
      KAFKA_NODE_ID: 1
      KAFKA_PROCESS_ROLES: broker,controller
      KAFKA_LISTENERS: PLAINTEXT://:9092,CONTROLLER://:9093
      KAFKA_ADVERTISED_LISTENERS: PLAINTEXT://${DOCKER_BRIDGE}:${PORT}
      KAFKA_CONTROLLER_LISTENER_NAMES: CONTROLLER
      KAFKA_LISTENER_SECURITY_PROTOCOL_MAP: CONTROLLER:PLAINTEXT,PLAINTEXT:PLAINTEXT
      KAFKA_CONTROLLER_QUORUM_VOTERS: 1@localhost:9093
      KAFKA_OFFSETS_TOPIC_REPLICATION_FACTOR: 1
      KAFKA_TRANSACTION_STATE_LOG_REPLICATION_FACTOR: 1
      KAFKA_TRANSACTION_STATE_LOG_MIN_ISR: 1
      KAFKA_GROUP_INITIAL_REBALANCE_DELAY_MS: 0
      KAFKA_LOG_DIRS: /var/lib/kafka/data
    ports:
      - "127.0.0.1:${PORT}:9092"
      - "${DOCKER_BRIDGE}:${PORT}:9092"
    volumes:
      - data:/var/lib/kafka/data
volumes:
  data:
YAML,
    ],
];
