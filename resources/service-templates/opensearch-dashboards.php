<?php

return [
    'name' => 'OpenSearch Dashboards',
    'category' => 'Infrastructure',
    'description' => 'Charts and search for your OpenSearch data, with a one-node OpenSearch bundled.',
    'icon' => 'opensearch',
    'kind' => 'compose',
    'port' => 5601,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/status',
    'notes' => 'The security plugin is off, so neither the dashboards nor OpenSearch has a login: put something in front before giving it a public domain. Apps on this machine reach OpenSearch at http://{bridge} on the OpenSearch port. The host needs vm.max_map_count of at least 262144 (sysctl -w vm.max_map_count=262144).',
    'env' => [
        ['key' => 'OPENSEARCH_PORT', 'label' => 'OpenSearch port', 'default' => '9200'],
        ['key' => 'HEAP', 'label' => 'Java heap size', 'default' => '512m'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  dashboards:
    image: opensearchproject/opensearch-dashboards:latest
    restart: unless-stopped
    depends_on: [opensearch]
    environment:
      OPENSEARCH_HOSTS: '["http://opensearch:9200"]'
      DISABLE_SECURITY_DASHBOARDS_PLUGIN: "true"
    ports:
      - "127.0.0.1:${PORT}:5601"
  opensearch:
    image: opensearchproject/opensearch:latest
    restart: unless-stopped
    environment:
      discovery.type: single-node
      bootstrap.memory_lock: "true"
      OPENSEARCH_JAVA_OPTS: -Xms${HEAP} -Xmx${HEAP}
      DISABLE_SECURITY_PLUGIN: "true"
      DISABLE_INSTALL_DEMO_CONFIG: "true"
    ulimits:
      memlock:
        soft: -1
        hard: -1
      nofile:
        soft: 65536
        hard: 65536
    ports:
      - "127.0.0.1:${OPENSEARCH_PORT}:9200"
      - "${DOCKER_BRIDGE}:${OPENSEARCH_PORT}:9200"
    volumes:
      - data:/usr/share/opensearch/data
volumes:
  data:
YAML,
    ],
];
