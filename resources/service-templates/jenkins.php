<?php

return [
    'name' => 'Jenkins',
    'category' => 'Infrastructure',
    'description' => 'The long-running automation server, on the LTS release.',
    'icon' => 'jenkins',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/login',
    'notes' => 'The setup wizard asks for an unlock password. It is printed in the service logs, and kept at /var/jenkins_home/secrets/initialAdminPassword. Then pick plugins and create the admin.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  jenkins:
    image: jenkins/jenkins:lts-jdk21
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:8080"
    volumes:
      - data:/var/jenkins_home
volumes:
  data:
YAML,
    ],
];
