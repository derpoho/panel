<?php

return [
    'name' => 'Coding agent',
    'category' => 'Self-hosted',
    'description' => 'Claude Code and Codex in a container on your machine, for workflows to run: they look into problems and open pull requests. Your keys and code stay on your server.',
    'icon' => 'bot',
    'kind' => 'compose',
    // Nothing listens: workflows run the agents inside it. The port is only
    // there because every template has one.
    'port' => 7070,
    'web' => false,
    'port_editable' => false,
    'health' => null,
    'notes' => 'Add a Run agent step to a workflow and choose this container. Connect your Anthropic or OpenAI key in Settings › AI and MCP and it’s handed to each run; or set ANTHROPIC_API_KEY or OPENAI_API_KEY here. Private repositories clone through ServerOS’s GitHub App, or set GH_TOKEN. The first start installs both agents, which takes a minute or two; each restart updates them.',
    'env' => [
        ['key' => 'ANTHROPIC_API_KEY', 'label' => 'Anthropic API key (optional)', 'hint' => 'For Claude Code. Not needed if the workspace’s key is connected in Settings › AI and MCP.', 'secret' => true],
        ['key' => 'OPENAI_API_KEY', 'label' => 'OpenAI API key (optional)', 'hint' => 'For Codex. Not needed if the workspace’s key is connected in Settings › AI and MCP.', 'secret' => true],
        ['key' => 'GH_TOKEN', 'label' => 'GitHub token (optional)', 'hint' => 'Only if ServerOS’s GitHub App can’t reach the repository: a fine-grained token with contents and pull requests read and write.', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  agent:
    image: node:22-bookworm
    restart: unless-stopped
    init: true
    environment:
      ANTHROPIC_API_KEY: ${ANTHROPIC_API_KEY}
      OPENAI_API_KEY: ${OPENAI_API_KEY}
      GH_TOKEN: ${GH_TOKEN}
      NPM_CONFIG_PREFIX: /opt/agent
      PATH: /opt/agent/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
      DISABLE_AUTOUPDATER: "1"
    # Install (or update) the agents, then wait for workflows to run them.
    command: ["sh", "-c", "npm install -g @anthropic-ai/claude-code @openai/codex || echo Could not install or update the agents; exec sleep infinity"]
    volumes:
      - tools:/opt/agent
      - home:/root
    deploy:
      resources:
        limits:
          memory: 4g
volumes:
  tools:
  home:
YAML,
    ],
];
