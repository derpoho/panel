<?php

return [
    'name' => 'RStudio Server',
    'category' => 'Infrastructure',
    'description' => 'The RStudio IDE for R in your browser, from the Rocker project.',
    'icon' => 'rstudioide',
    'kind' => 'compose',
    'port' => 8787,
    'web' => true,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Sign in as rstudio with the password from your settings. Your home folder is kept in a volume.',
    'env' => [
        ['key' => 'PASSWORD', 'label' => 'Password', 'default' => '{random:20}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  rstudio:
    image: rocker/rstudio:latest
    restart: unless-stopped
    environment:
      PASSWORD: ${PASSWORD}
    ports:
      - "127.0.0.1:${PORT}:8787"
    volumes:
      - home:/home/rstudio
volumes:
  home:
YAML,
    ],
];
