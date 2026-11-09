<?php

return [
    'name' => 'AdonisJS',
    'category' => 'Apps',
    'description' => 'AdonisJS 6: builds with node ace build and runs the compiled server.',
    'icon' => 'adonisjs',
    'kind' => 'app',
    'port' => 3333,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Migrations do not run on their own. Point your database settings at a Postgres or MySQL service and run node ace migration:run --force once it is up.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [
        ['key' => 'APP_KEY', 'label' => 'App key', 'default' => '{random:32}', 'secret' => true, 'hint' => 'Generated. Keep it the same between deploys.'],
        ['key' => 'LOG_LEVEL', 'label' => 'Log level', 'default' => 'info'],
        ['key' => 'SESSION_DRIVER', 'label' => 'Session driver', 'default' => 'cookie'],
    ],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM node:22-alpine AS build
WORKDIR /app
COPY package*.json ./
RUN npm ci || npm install
COPY . .
RUN node ace build

FROM node:22-alpine
WORKDIR /app
COPY --from=build /app/build ./
RUN npm ci --omit=dev || npm install --omit=dev
ENV NODE_ENV=production HOST=0.0.0.0 PORT=3333
EXPOSE 3333
CMD ["node", "bin/server.js"]
DOCKERFILE,
    ],
];
