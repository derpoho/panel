<?php

return [
    'name' => 'Symfony',
    'category' => 'Apps',
    'description' => 'PHP 8.4 with Nginx and PHP-FPM, Composer install and cache warm-up.',
    'icon' => 'symfony',
    'kind' => 'app',
    'port' => 8080,
    'web' => true,
    'port_editable' => false,
    'health' => '/',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [
        ['key' => 'APP_ENV', 'label' => 'Environment', 'default' => 'prod'],
        ['key' => 'APP_SECRET', 'label' => 'App secret', 'default' => '{random:32}', 'secret' => true],
    ],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM composer:2 AS vendor
WORKDIR /app
COPY . .
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --optimize-autoloader --ignore-platform-reqs

FROM serversideup/php:8.4-fpm-nginx
ENV PHP_OPCACHE_ENABLE=1 NGINX_WEBROOT=/var/www/html/public
WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=vendor /app/vendor ./vendor
DOCKERFILE,
    ],
];
