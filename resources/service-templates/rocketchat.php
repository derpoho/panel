<?php

return [
    'name' => 'Rocket.Chat',
    'category' => 'Self-hosted',
    'description' => 'Team chat with channels, threads and calls, with MongoDB.',
    'icon' => 'rocketdotchat',
    'kind' => 'compose',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/info',
    'notes' => 'Open it in the browser to run the setup wizard and create the admin account. The first start takes a minute or two.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  rocketchat:
    image: registry.rocket.chat/rocketchat/rocket.chat:latest
    restart: unless-stopped
    depends_on:
      mongodb:
        condition: service_healthy
    environment:
      ROOT_URL: ${APP_URL}
      PORT: 3000
      MONGO_URL: mongodb://mongodb:27017/rocketchat?replicaSet=rs0
      DEPLOY_METHOD: docker
    ports:
      - "127.0.0.1:${PORT}:3000"
    volumes:
      - uploads:/app/uploads
  mongodb:
    image: mongo:8
    restart: unless-stopped
    command: ["mongod", "--replSet", "rs0", "--oplogSize", "128"]
    # Sets up the single-node replica set Rocket.Chat needs, then reports healthy.
    healthcheck:
      test: ["CMD", "mongosh", "--quiet", "--eval", "try { rs.status().ok } catch (e) { rs.initiate({_id: 'rs0', members: [{_id: 0, host: 'mongodb:27017'}]}).ok }"]
      interval: 10s
      timeout: 10s
      retries: 10
      start_period: 20s
    volumes:
      - db:/data/db
volumes:
  uploads:
  db:
YAML,
    ],
];
