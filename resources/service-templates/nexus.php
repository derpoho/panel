<?php

return [
    'name' => 'Sonatype Nexus',
    'category' => 'Infrastructure',
    'description' => 'Artifact repository for Maven, npm, PyPI, Docker and more.',
    'icon' => 'sonatype',
    'kind' => 'compose',
    'port' => 8081,
    'web' => true,
    'port_editable' => true,
    'health' => '/service/rest/v1/status',
    'notes' => 'Sign in as admin. The first password is in /nexus-data/admin.password inside the container, and it asks you to change it. It wants about 3 GB of RAM and takes a couple of minutes to start. Docker registries need their own port, which this template does not open.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  nexus:
    image: sonatype/nexus3:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:8081"
      - "${DOCKER_BRIDGE}:${PORT}:8081"
    volumes:
      - data:/nexus-data
volumes:
  data:
YAML,
    ],
];
