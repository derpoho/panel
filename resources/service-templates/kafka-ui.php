<?php

return [
    'name' => 'Kafka UI',
    'category' => 'Infrastructure',
    'description' => 'Web UI for browsing Kafka topics, messages and consumer groups.',
    'icon' => 'apachekafka',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/actuator/health',
    'notes' => 'For Kafka on this same machine, use {bridge} and its port as the bootstrap server, like {bridge}:9092. Sign in with the admin user and password you set here.',
    'env' => [
        ['key' => 'KAFKA_BOOTSTRAP', 'label' => 'Bootstrap servers', 'default' => '', 'required' => true, 'hint' => 'host:port, comma separated'],
        ['key' => 'CLUSTER_NAME', 'label' => 'Cluster name', 'default' => 'local'],
        ['key' => 'ADMIN_USER', 'label' => 'Admin user', 'default' => 'admin'],
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  kafka-ui:
    image: ghcr.io/kafbat/kafka-ui:latest
    restart: unless-stopped
    environment:
      KAFKA_CLUSTERS_0_NAME: ${CLUSTER_NAME}
      KAFKA_CLUSTERS_0_BOOTSTRAPSERVERS: ${KAFKA_BOOTSTRAP}
      AUTH_TYPE: LOGIN_FORM
      SPRING_SECURITY_USER_NAME: ${ADMIN_USER}
      SPRING_SECURITY_USER_PASSWORD: ${ADMIN_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:8080"
YAML,
    ],
];
