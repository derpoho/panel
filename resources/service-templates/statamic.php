<?php

return [
    'name' => 'Statamic',
    'category' => 'Apps',
    'description' => 'Your Statamic site on PHP 8.4 with Nginx and PHP-FPM.',
    'icon' => 'statamic',
    'kind' => 'app',
    'port' => 8080,
    'web' => true,
    'port_editable' => false,
    'health' => '/',
    'notes' => 'Content lives in your repository, so commit entries and redeploy. Edits made in the control panel on the server are lost on the next deploy unless you use Statamic Git or a database driver.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [
        ['key' => 'APP_KEY', 'label' => 'App key', 'default' => '{base64:32}', 'secret' => true, 'hint' => 'Generated. Keep it the same between deploys.'],
        ['key' => 'APP_ENV', 'label' => 'Environment', 'default' => 'production'],
        ['key' => 'APP_DEBUG', 'label' => 'Debug mode', 'default' => 'false'],
        ['key' => 'APP_URL', 'label' => 'App URL', 'default' => '{url}'],
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
USER root
RUN install-php-extensions exif gd intl
USER www-data
ENV AUTORUN_ENABLED=true PHP_OPCACHE_ENABLE=1
WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=vendor /app/vendor ./vendor
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build
DOCKERFILE,
    ],
];
