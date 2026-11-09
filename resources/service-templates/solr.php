<?php

return [
    'name' => 'Apache Solr',
    'category' => 'Infrastructure',
    'description' => 'Full-text search server, with one core made for you on first start.',
    'icon' => 'apachesolr',
    'kind' => 'compose',
    'port' => 8983,
    'web' => true,
    'port_editable' => true,
    'health' => '/solr/admin/info/system',
    'notes' => 'The admin UI is at /solr and has no login, so put something in front before giving it a public domain. Apps on this machine connect to http://{bridge}:PORT/solr/CORE.',
    'env' => [
        ['key' => 'CORE', 'label' => 'Core name', 'default' => 'app'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  solr:
    image: solr:9
    restart: unless-stopped
    command: ["solr-precreate", "${CORE}"]
    ports:
      - "127.0.0.1:${PORT}:8983"
      - "${DOCKER_BRIDGE}:${PORT}:8983"
    volumes:
      - data:/var/solr
volumes:
  data:
YAML,
    ],
];
