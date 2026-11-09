<?php

return [
    'name' => 'Zola',
    'category' => 'Apps',
    'description' => 'Builds your Zola site and serves public/ with Nginx.',
    'icon' => 'zola',
    'kind' => 'app',
    'port' => 80,
    'web' => true,
    'port_editable' => false,
    'health' => '/',
    'notes' => 'Uses the base_url in your config.toml.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM alpine:3.20 AS build
RUN apk add --no-cache zola
WORKDIR /site
COPY . .
RUN zola build --output-dir /out

FROM nginx:1.27-alpine
COPY --from=build /out /usr/share/nginx/html
DOCKERFILE,
    ],
];
