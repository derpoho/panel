<?php

return [
    'name' => 'PHP',
    'category' => 'Apps',
    'description' => 'Plain PHP 8.4 site served by Nginx, document root at public/ if it exists.',
    'icon' => 'php',
    'kind' => 'app',
    'port' => 8080,
    'web' => true,
    'port_editable' => false,
    'health' => '/',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM serversideup/php:8.4-fpm-nginx
WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
RUN if [ -d public ]; then echo "public/ found"; else mkdir -p public && cp -r *.php public/ 2>/dev/null || true; fi
ENV NGINX_WEBROOT=/var/www/html/public
DOCKERFILE,
    ],
];
