<?php

return [
    'name' => 'discord.js',
    'category' => 'Apps',
    'description' => 'A Discord bot written with discord.js: installs your packages, builds if there is a build script, then runs your start command.',
    'icon' => 'discordjs',
    'kind' => 'app',
    // Bots don't serve web pages: the deploy checks the bot keeps running
    // instead. The port is only there because every app has one.
    'port' => 3000,
    'web' => false,
    'port_editable' => false,
    'notes' => 'Your bot token is in the DISCORD_TOKEN environment variable, so log in with process.env.DISCORD_TOKEN. The deploy passes once the bot has stayed running for a few seconds.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [
        ['key' => 'DISCORD_TOKEN', 'label' => 'Bot token', 'hint' => 'From the Bot page of your app in the Discord Developer Portal.', 'secret' => true, 'required' => true],
        ['key' => 'START_COMMAND', 'label' => 'Start command', 'default' => 'npm start', 'hint' => 'For example node index.js if package.json has no start script.'],
    ],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM node:22-alpine
WORKDIR /app
COPY package*.json ./
RUN npm ci || npm install
COPY . .
RUN npm run build --if-present && npm prune --omit=dev
ENV NODE_ENV=production
CMD ["sh", "-c", "exec ${START_COMMAND:-npm start}"]
DOCKERFILE,
    ],
];
