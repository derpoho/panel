<?php

return [
    'name' => 'Slim',
    'category' => 'Apps',
    'description' => 'Your Slim app on PHP 8.4 with Nginx and PHP-FPM, serving public/index.php.',
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
FROM composer:2 AS vendor
WORKDIR /app
COPY . .
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --optimize-autoloader --ignore-platform-reqs

FROM serversideup/php:8.4-fpm-nginx
ENV PHP_OPCACHE_ENABLE=1
WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=vendor /app/vendor ./vendor
DOCKERFILE,
    ],
];
