<?php

return [
    'name' => 'RabbitMQ',
    'category' => 'Infrastructure',
    'description' => 'Message broker with the management UI on localhost:15672.',
    'icon' => 'rabbitmq',
    'kind' => 'compose',
    'port' => 5672,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'env' => [
        ['key' => 'RABBITMQ_USER', 'label' => 'User', 'default' => 'app'],
        ['key' => 'RABBITMQ_PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  rabbitmq:
    image: rabbitmq:4-management
    restart: unless-stopped
    environment:
      RABBITMQ_DEFAULT_USER: ${RABBITMQ_USER}
      RABBITMQ_DEFAULT_PASS: ${RABBITMQ_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:5672"
      - "127.0.0.1:15672:15672"
    volumes:
      - data:/var/lib/rabbitmq
volumes:
  data:
YAML,
    ],
];
