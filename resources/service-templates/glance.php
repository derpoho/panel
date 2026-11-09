<?php

return [
    'name' => 'Glance',
    'category' => 'Self-hosted',
    'description' => 'A fast dashboard for your feeds, weather, markets and services.',
    'icon' => 'glance',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/api/healthz',
    'notes' => 'The layout lives in glance.yml next to the compose file. Edit it and restart to change the page.',
    'env' => [],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  glance:
    image: glanceapp/glance:latest
    restart: unless-stopped
    ports:
      - "127.0.0.1:${PORT}:8080"
    volumes:
      - ./glance.yml:/app/config/glance.yml:ro
YAML,
        'glance.yml' => <<<'YAML'
pages:
  - name: Home
    columns:
      - size: small
        widgets:
          - type: calendar
            first-day-of-week: monday
          - type: rss
            limit: 10
            collapse-after: 3
            cache: 12h
            feeds:
              - url: https://selfh.st/rss/
                title: selfh.st
      - size: full
        widgets:
          - type: search
            search-engine: duckduckgo
          - type: hacker-news
          - type: lobsters
      - size: small
        widgets:
          - type: weather
            location: London, United Kingdom
            units: metric
YAML,
    ],
];
