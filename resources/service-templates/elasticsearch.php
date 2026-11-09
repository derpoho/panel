<?php

return [
    'name' => 'Elasticsearch',
    'category' => 'Infrastructure',
    'description' => 'Search and analytics engine, as a single node with a password.',
    'icon' => 'elasticsearch',
    'kind' => 'compose',
    'port' => 9200,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Connect from this machine at http://elastic:PASSWORD@127.0.0.1:PORT. It only listens locally. Needs vm.max_map_count of at least 262144 on the host.',
    'env' => [
        ['key' => 'ELASTIC_PASSWORD', 'label' => 'Password for elastic', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'HEAP_SIZE', 'label' => 'Java heap', 'default' => '1g'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  elasticsearch:
    image: elasticsearch:8.17.0
    restart: unless-stopped
    environment:
      discovery.type: single-node
      ELASTIC_PASSWORD: ${ELASTIC_PASSWORD}
      xpack.security.enabled: "true"
      xpack.security.http.ssl.enabled: "false"
      ES_JAVA_OPTS: -Xms${HEAP_SIZE} -Xmx${HEAP_SIZE}
    ulimits:
      memlock:
        soft: -1
        hard: -1
    ports:
      - "127.0.0.1:${PORT}:9200"
    volumes:
      - data:/usr/share/elasticsearch/data
volumes:
  data:
YAML,
    ],
];
