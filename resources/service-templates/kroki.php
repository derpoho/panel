<?php

return [
    'name' => 'Kroki',
    'category' => 'Self-hosted',
    'description' => 'One API that renders Mermaid, PlantUML, Graphviz and many more diagram types.',
    'icon' => 'code',
    'kind' => 'compose',
    'port' => 8000,
    'web' => true,
    'port_editable' => true,
    'health' => '/health',
    'notes' => 'Send diagram text to /{type}/svg, for example /mermaid/svg. Mermaid runs in its own container.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  kroki:
    image: yuzutech/kroki:latest
    restart: unless-stopped
    depends_on: [mermaid]
    environment:
      KROKI_MERMAID_HOST: mermaid
    ports:
      - "127.0.0.1:${PORT}:8000"
  mermaid:
    image: yuzutech/kroki-mermaid:latest
    restart: unless-stopped
YAML,
    ],
];
