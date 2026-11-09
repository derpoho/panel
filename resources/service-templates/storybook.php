<?php

return [
    'name' => 'Storybook',
    'category' => 'Apps',
    'description' => 'Builds your Storybook and serves it as a static site with Nginx.',
    'icon' => 'storybook',
    'kind' => 'app',
    'port' => 80,
    'web' => true,
    'port_editable' => false,
    'health' => '/',
    'notes' => 'Runs your build-storybook script if you have one, otherwise storybook build.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM node:22-alpine AS build
WORKDIR /app
COPY package*.json ./
RUN npm ci || npm install
COPY . .
RUN if node -e "process.exit(require('./package.json').scripts?.['build-storybook'] ? 0 : 1)"; then npm run build-storybook -- -o storybook-static; else npx storybook build -o storybook-static; fi

FROM nginx:1.27-alpine
COPY --from=build /app/storybook-static /usr/share/nginx/html
DOCKERFILE,
    ],
];
