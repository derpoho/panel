<?php

return [
    'name' => 'DiscordPHP',
    'category' => 'Apps',
    'description' => 'A Discord bot written with DiscordPHP on PHP 8.4: installs your Composer packages, then runs your start command.',
    'icon' => 'discord',
    'kind' => 'app',
    // Bots don't serve web pages: the deploy checks the bot keeps running
    // instead. The port is only there because every app has one.
    'port' => 8080,
    'web' => false,
    'port_editable' => false,
    'notes' => 'Your bot token is in the DISCORD_TOKEN environment variable, so log in with getenv(\'DISCORD_TOKEN\'). The deploy passes once the bot has stayed running for a few seconds.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [
        ['key' => 'DISCORD_TOKEN', 'label' => 'Bot token', 'hint' => 'From the Bot page of your app in the Discord Developer Portal.', 'secret' => true, 'required' => true],
        ['key' => 'START_COMMAND', 'label' => 'Start command', 'default' => 'php bot.php', 'hint' => 'For example php run.php.'],
    ],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM php:8.4-cli-alpine
RUN apk add --no-cache git unzip
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --optimize --no-dev
CMD ["sh", "-c", "exec ${START_COMMAND:-php bot.php}"]
DOCKERFILE,
    ],
];
