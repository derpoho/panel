<?php

return [
    'name' => 'Laravel',
    'category' => 'Apps',
    'description' => 'PHP 8.4 with Nginx and PHP-FPM. Runs migrations on boot.',
    'icon' => 'laravel',
    'kind' => 'app',
    'port' => 8080,
    'web' => true,
    'port_editable' => false,
    'health' => '/up',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [
        ['key' => 'APP_KEY', 'label' => 'App key', 'default' => '{base64:32}', 'secret' => true, 'hint' => 'Generated. Keep it the same between deploys.'],
        ['key' => 'APP_ENV', 'label' => 'Environment', 'default' => 'production'],
        ['key' => 'APP_DEBUG', 'label' => 'Debug mode', 'default' => 'false'],
        ['key' => 'APP_URL', 'label' => 'App URL', 'default' => '{url}'],
        ['key' => 'DB_CONNECTION', 'label' => 'Database driver', 'default' => 'sqlite', 'hint' => 'Point this at a Postgres or MySQL service for production.'],
        ['key' => 'LOG_CHANNEL', 'label' => 'Logs', 'default' => 'stderr', 'hint' => 'stderr puts Laravel’s errors in the container’s output, where ServerOS’s Logs page (and Ask AI) can read them.'],
    ],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM composer:2 AS vendor
WORKDIR /app
COPY . .
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --optimize-autoloader --ignore-platform-reqs

FROM node:22-alpine AS assets
WORKDIR /app
COPY . .
RUN if [ -f package.json ]; then (npm ci || npm install) && npm run build; fi; mkdir -p public/build

FROM serversideup/php:8.4-fpm-nginx
ENV AUTORUN_ENABLED=true PHP_OPCACHE_ENABLE=1
WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=vendor /app/vendor ./vendor
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build
DOCKERFILE,
    ],
];
