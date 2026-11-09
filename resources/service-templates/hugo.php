<?php

return [
    'name' => 'Hugo',
    'category' => 'Apps',
    'description' => 'Builds your Hugo site (extended edition) and serves public/ with Nginx. Uses the baseURL in your config.',
    'icon' => 'hugo',
    'kind' => 'app',
    'port' => 80,
    'web' => true,
    'port_editable' => false,
    'health' => '/',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM hugomods/hugo:exts AS build
WORKDIR /src
COPY . .
RUN hugo --minify --gc

FROM nginx:1.27-alpine
COPY --from=build /src/public /usr/share/nginx/html
DOCKERFILE,
    ],
];
