<?php

return [
    'name' => 'WeKan',
    'category' => 'Self-hosted',
    'description' => 'Kanban boards like Trello, with swimlanes, checklists and due dates, with MongoDB.',
    'icon' => 'table',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Open it in the browser and register: the first account becomes the admin.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  wekan:
    image: ghcr.io/wekan/wekan:latest
    restart: unless-stopped
    depends_on: [db]
    environment:
      ROOT_URL: ${APP_URL}
      PORT: 8080
      MONGO_URL: mongodb://db:27017/wekan
      WRITABLE_PATH: /data
      WITH_API: "true"
      DEFAULT_METEOR_REACTIVITY_ORDER: polling
      METEOR_REACTIVITY_ORDER: polling
      BROWSER_POLICY_ENABLED: "true"
    ports:
      - "127.0.0.1:${PORT}:8080"
    volumes:
      - files:/data
  db:
    image: mongo:7
    restart: unless-stopped
    command: ["mongod", "--oplogSize", "128"]
    volumes:
      - db:/data/db
volumes:
  files:
  db:
YAML,
    ],
];
