<?php

return [
    'name' => 'Woodpecker CI',
    'category' => 'Infrastructure',
    'description' => 'Simple CI that runs pipelines in containers, with a server and one agent.',
    'icon' => 'workflow',
    'kind' => 'compose',
    'port' => 8000,
    'web' => true,
    'port_editable' => true,
    'health' => '/healthz',
    'notes' => 'It signs in through GitHub. Make an OAuth app on GitHub with the callback URL set to your public URL plus /authorize, and fill in its client ID and secret. Only the GitHub users you list as admins can manage it. The agent runs pipeline steps as containers through the machine\'s Docker socket.',
    'env' => [
        ['key' => 'APP_URL', 'label' => 'Public URL', 'default' => '{url}'],
        ['key' => 'GITHUB_CLIENT', 'label' => 'GitHub OAuth client ID', 'required' => true],
        ['key' => 'GITHUB_SECRET', 'label' => 'GitHub OAuth client secret', 'required' => true, 'secret' => true],
        ['key' => 'ADMINS', 'label' => 'Admin GitHub usernames', 'default' => '', 'hint' => 'Comma separated'],
        ['key' => 'AGENT_SECRET', 'label' => 'Agent secret', 'default' => '{random:48}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  server:
    image: woodpeckerci/woodpecker-server:v3
    restart: unless-stopped
    environment:
      WOODPECKER_HOST: ${APP_URL}
      WOODPECKER_OPEN: "false"
      WOODPECKER_ADMIN: ${ADMINS}
      WOODPECKER_GITHUB: "true"
      WOODPECKER_GITHUB_CLIENT: ${GITHUB_CLIENT}
      WOODPECKER_GITHUB_SECRET: ${GITHUB_SECRET}
      WOODPECKER_AGENT_SECRET: ${AGENT_SECRET}
    ports:
      - "127.0.0.1:${PORT}:8000"
    volumes:
      - server:/var/lib/woodpecker
  agent:
    image: woodpeckerci/woodpecker-agent:v3
    restart: unless-stopped
    command: agent
    depends_on: [server]
    environment:
      WOODPECKER_SERVER: server:9000
      WOODPECKER_AGENT_SECRET: ${AGENT_SECRET}
      WOODPECKER_BACKEND: docker
    volumes:
      - agent:/etc/woodpecker
      - /var/run/docker.sock:/var/run/docker.sock
volumes:
  server:
  agent:
YAML,
    ],
];
