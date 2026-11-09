<?php

return [
    'name' => 'GitLab CE',
    'category' => 'Infrastructure',
    'description' => 'The full GitLab: Git hosting, merge requests, CI/CD, registry and wiki.',
    'icon' => 'gitlab',
    'kind' => 'compose',
    'port' => 8929,
    'web' => true,
    'port_editable' => true,
    'health' => '/users/sign_in',
    'notes' => 'It is heavy: give the machine at least 4 GB of RAM (8 GB is better) and expect the first start to take five minutes or more. Sign in as root with the root password from your settings. Git over SSH is open to the internet on the SSH port. CI runners are separate; register one from Admin › CI/CD.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'ROOT_PASSWORD', 'label' => 'Root password', 'default' => '{random:24}', 'secret' => true],
        ['key' => 'SSH_PORT', 'label' => 'SSH port', 'default' => '2222'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  gitlab:
    image: gitlab/gitlab-ce:latest
    restart: unless-stopped
    shm_size: 256m
    environment:
      GITLAB_ROOT_PASSWORD: ${ROOT_PASSWORD}
      GITLAB_OMNIBUS_CONFIG: |
        external_url '${APP_URL}'
        nginx['listen_port'] = 80
        nginx['listen_https'] = false
        letsencrypt['enable'] = false
        gitlab_rails['gitlab_shell_ssh_port'] = ${SSH_PORT}
        puma['worker_processes'] = 2
        sidekiq['concurrency'] = 10
        prometheus_monitoring['enable'] = false
    ports:
      - "127.0.0.1:${PORT}:80"
      - "${SSH_PORT}:22"
    volumes:
      - config:/etc/gitlab
      - logs:/var/log/gitlab
      - data:/var/opt/gitlab
volumes:
  config:
  logs:
  data:
YAML,
    ],
];
