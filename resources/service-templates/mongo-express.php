<?php

return [
    'name' => 'mongo-express',
    'category' => 'Infrastructure',
    'description' => 'Web admin for MongoDB, with a MongoDB 8 server bundled.',
    'icon' => 'mongodb',
    'kind' => 'compose',
    'port' => 8081,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'The browser asks for the admin user and password you set here. The bundled MongoDB is only reachable from mongo-express.',
    'env' => [
        ['key' => 'ADMIN_USER', 'label' => 'Admin user', 'default' => 'admin'],
        ['key' => 'ADMIN_PASSWORD', 'label' => 'Admin password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'MONGO_PASSWORD', 'label' => 'MongoDB root password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  mongo-express:
    image: mongo-express:latest
    restart: unless-stopped
    depends_on: [mongo]
    environment:
      ME_CONFIG_MONGODB_URL: mongodb://root:${MONGO_PASSWORD}@mongo:27017/
      ME_CONFIG_BASICAUTH: "true"
      ME_CONFIG_BASICAUTH_USERNAME: ${ADMIN_USER}
      ME_CONFIG_BASICAUTH_PASSWORD: ${ADMIN_PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:8081"
  mongo:
    image: mongo:8
    restart: unless-stopped
    environment:
      MONGO_INITDB_ROOT_USERNAME: root
      MONGO_INITDB_ROOT_PASSWORD: ${MONGO_PASSWORD}
    volumes:
      - db:/data/db
volumes:
  db:
YAML,
    ],
];
