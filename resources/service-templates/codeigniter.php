<?php

return [
    'name' => 'CodeIgniter',
    'category' => 'Apps',
    'description' => 'CodeIgniter 4 on PHP 8.4 with Nginx and PHP-FPM, serving public/.',
    'icon' => 'codeigniter',
    'kind' => 'app',
    'port' => 8080,
    'web' => true,
    'port_editable' => false,
    'health' => '/',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [
        ['key' => 'CI_ENVIRONMENT', 'label' => 'Environment', 'default' => 'production'],
        ['key' => 'app_baseURL', 'label' => 'Base URL', 'default' => '{url}', 'hint' => 'Overrides app.baseURL. Keep the trailing slash.'],
        ['key' => 'encryption_key', 'label' => 'Encryption key', 'default' => '{random:32}', 'secret' => true, 'hint' => 'Overrides encryption.key. Keep it the same between deploys.'],
    ],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM composer:2 AS vendor
WORKDIR /app
COPY . .
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --optimize-autoloader --ignore-platform-reqs

FROM serversideup/php:8.4-fpm-nginx
USER root
RUN install-php-extensions intl
USER www-data
ENV PHP_OPCACHE_ENABLE=1
WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=vendor /app/vendor ./vendor
RUN mkdir -p writable/cache writable/logs writable/session writable/uploads
DOCKERFILE,
    ],
];
