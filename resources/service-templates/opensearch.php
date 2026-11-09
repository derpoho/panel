<?php

return [
    'name' => 'OpenSearch',
    'category' => 'Infrastructure',
    'description' => 'Search and analytics engine, one node with its data kept in a volume.',
    'icon' => 'opensearch',
    'kind' => 'compose',
    'port' => 9200,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Clients connect to http://{bridge}:PORT, from this machine and from apps you deploy on it. The security plugin is off, so there is no login and it only listens locally and on the Docker bridge. The host needs vm.max_map_count of at least 262144 (sysctl -w vm.max_map_count=262144).',
    'env' => [
        ['key' => 'HEAP', 'label' => 'Java heap size', 'default' => '512m'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
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
      - "127.0.0.1:${PORT}:9200"
      - "${DOCKER_BRIDGE}:${PORT}:9200"
    volumes:
      - data:/usr/share/opensearch/data
volumes:
  data:
YAML,
    ],
];
