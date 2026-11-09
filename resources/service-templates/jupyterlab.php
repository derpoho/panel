<?php

return [
    'name' => 'JupyterLab',
    'category' => 'Infrastructure',
    'description' => 'Notebooks in the browser, with the scientific Python stack installed.',
    'icon' => 'jupyter',
    'kind' => 'compose',
    'port' => 8888,
    'web' => true,
    'port_editable' => true,
    'health' => '/api',
    'notes' => 'Sign in with the token from your settings. Save your work under work/, which is kept in a volume.',
    'env' => [
        ['key' => 'TOKEN', 'label' => 'Access token', 'default' => '{random:32}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  jupyterlab:
    image: quay.io/jupyter/scipy-notebook:latest
    restart: unless-stopped
    environment:
      JUPYTER_TOKEN: ${TOKEN}
    ports:
      - "127.0.0.1:${PORT}:8888"
    volumes:
      - work:/home/jovyan/work
volumes:
  work:
YAML,
    ],
];
