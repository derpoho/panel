<?php

return [
    'name' => 'CakePHP',
    'category' => 'Apps',
    'description' => 'CakePHP 5 on PHP 8.4 with Nginx and PHP-FPM, serving webroot/.',
    'icon' => 'cakephp',
    'kind' => 'app',
    'port' => 8080,
    'web' => true,
    'port_editable' => false,
    'health' => '/',
    'notes' => 'Reads SECURITY_SALT and DEBUG through config/app_local.php, like the app skeleton does. Add DATABASE_URL if you use a database.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [
        ['key' => 'SECURITY_SALT', 'label' => 'Security salt', 'default' => '{random:64}', 'secret' => true, 'hint' => 'Keep it the same between deploys.'],
        ['key' => 'DEBUG', 'label' => 'Debug mode', 'default' => 'false'],
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
ENV NGINX_WEBROOT=/var/www/html/webroot PHP_OPCACHE_ENABLE=1
WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=vendor /app/vendor ./vendor
RUN mkdir -p tmp/cache/models tmp/cache/persistent tmp/cache/views tmp/sessions logs \
    && if [ ! -f config/app_local.php ] && [ -f config/app_local.example.php ]; then cp config/app_local.example.php config/app_local.php; fi
DOCKERFILE,
    ],
];
