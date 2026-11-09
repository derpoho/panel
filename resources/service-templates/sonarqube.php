<?php

return [
    'name' => 'SonarQube',
    'category' => 'Infrastructure',
    'description' => 'Code quality and security scanning for your repos, community edition with Postgres.',
    'icon' => 'sonarqubeserver',
    'kind' => 'compose',
    'port' => 9000,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/system/status',
    'notes' => 'The first login is admin / admin, and it makes you change it. It wants about 2 GB of RAM, and the host needs vm.max_map_count of at least 524288 (sysctl -w vm.max_map_count=524288).',
    'env' => [
        ['key' => 'DB_PASSWORD', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  sonarqube:
    image: sonarqube:community
    restart: unless-stopped
    depends_on: [db]
    environment:
      SONAR_JDBC_URL: jdbc:postgresql://db:5432/sonar
      SONAR_JDBC_USERNAME: sonar
      SONAR_JDBC_PASSWORD: ${DB_PASSWORD}
    ulimits:
      nofile:
        soft: 131072
        hard: 131072
    ports:
      - "127.0.0.1:${PORT}:9000"
    volumes:
      - data:/opt/sonarqube/data
      - extensions:/opt/sonarqube/extensions
      - logs:/opt/sonarqube/logs
  db:
    image: postgres:17
    restart: unless-stopped
    environment:
      POSTGRES_DB: sonar
      POSTGRES_USER: sonar
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db:/var/lib/postgresql/data
volumes:
  data:
  extensions:
  logs:
  db:
YAML,
    ],
];
