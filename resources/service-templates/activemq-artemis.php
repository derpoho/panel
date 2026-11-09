<?php

return [
    'name' => 'ActiveMQ Artemis',
    'category' => 'Infrastructure',
    'description' => 'Message broker that speaks AMQP, STOMP, MQTT, OpenWire and JMS.',
    'icon' => 'apache',
    'kind' => 'compose',
    'port' => 8161,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'The main port is the web console, at /console. Sign in with the user and password you set here. Clients connect to the broker at {bridge} on the broker port, which takes every protocol.',
    'env' => [
        ['key' => 'ARTEMIS_USER', 'label' => 'User', 'default' => 'artemis'],
        ['key' => 'ARTEMIS_PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'BROKER_PORT', 'label' => 'Broker port', 'default' => '61616'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  artemis:
    image: apache/activemq-artemis:latest
    restart: unless-stopped
    environment:
      ARTEMIS_USER: ${ARTEMIS_USER}
      ARTEMIS_PASSWORD: ${ARTEMIS_PASSWORD}
      ANONYMOUS_LOGIN: "false"
    ports:
      - "127.0.0.1:${PORT}:8161"
      - "127.0.0.1:${BROKER_PORT}:61616"
      - "${DOCKER_BRIDGE}:${BROKER_PORT}:61616"
    volumes:
      - data:/var/lib/artemis-instance
volumes:
  data:
YAML,
    ],
];
