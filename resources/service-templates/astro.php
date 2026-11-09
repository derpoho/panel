<?php

return [
    'name' => 'Astro',
    'category' => 'Apps',
    'description' => 'Builds a static Astro site and serves dist/ with Nginx.',
    'icon' => 'astro',
    'kind' => 'app',
    'port' => 80,
    'web' => true,
    'port_editable' => false,
    'health' => '/',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM node:22-alpine AS build
WORKDIR /app
COPY package*.json ./
RUN npm ci || npm install
COPY . .
RUN npm run build

FROM nginx:1.27-alpine
COPY --from=build /app/dist /usr/share/nginx/html
DOCKERFILE,
    ],
];
